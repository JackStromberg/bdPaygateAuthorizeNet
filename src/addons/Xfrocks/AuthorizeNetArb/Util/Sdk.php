<?php

namespace Xfrocks\AuthorizeNetArb\Util;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use net\authorize\api\constants as AnetConstants;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Purchasable\Purchase;
use XF\Util\File;
use Xfrocks\AuthorizeNetArb\Util\Sdk\BaseResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\ChargeBaseResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\ChargeResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\CreateCustomerProfileResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\GetTransactionDetailsResult;
use Xfrocks\AuthorizeNetArb\Util\Sdk\SubscribeResult;

class Sdk
{
    const RESPONSE_CODE_TRANSACTION_APPROVED = '1';
    const RESPONSE_OK = 'Ok';

    const SUBSCRIBE_MAX_ATTEMPTS = 3;

    const WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE = 'net.authorize.payment.authcapture.created';
    const WEBHOOK_EVENT_TYPE_REFUND = 'net.authorize.payment.refund.created';
    const WEBHOOK_EVENT_TYPE_VOID = 'net.authorize.payment.void.created';

    /**
     * @param string $apiLoginId
     * @param string $transactionKey
     * @param string $callbackUrl
     * @return void
     * @throws \Exception
     */
    public static function assertWebhookExists($apiLoginId, $transactionKey, $callbackUrl)
    {
        self::autoload();

        $url = self::getEndpoint() . '/rest/v1/webhooks';
        $eventTypes = [
            self::WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE,
            self::WEBHOOK_EVENT_TYPE_REFUND,
            self::WEBHOOK_EVENT_TYPE_VOID,
        ];
        $existingWebhook = null;
        $existingEventTypes = [];

        $webhooks = self::createHttpRequestAndSend($apiLoginId, $transactionKey, $url);
        if (is_array($webhooks)) {
            foreach ($webhooks as $webhook) {
                if ($webhook['url'] === $callbackUrl) {
                    $existingWebhook = $webhook;
                    break;
                }
            }
        }

        if ($existingWebhook !== null) {
            foreach ($eventTypes as $eventType) {
                foreach ($existingWebhook['eventTypes'] as $existingWebhookEventType) {
                    if ($existingWebhookEventType === $eventType) {
                        $existingEventTypes[] = $existingWebhookEventType;
                    }
                }
            }
        }

        if ($existingWebhook !== null && count($existingEventTypes) === count($eventTypes)) {
            // existing webhook found and is configured properly
            return;
        }

        $json = [
            'url' => $callbackUrl,
            'eventTypes' => $eventTypes,
            'status' => 'active',
        ];
        if ($existingWebhook === null) {
            $newWebhook = self::createHttpRequestAndSend($apiLoginId, $transactionKey, $url, 'POST', $json);
            if (!is_array($newWebhook) || !isset($newWebhook['webhookId'])) {
                throw new \Exception('Webhook cannot be created');
            }
        } else {
            $updateUrl = self::getEndpoint() . $existingWebhook['_links']['self']['href'];
            $updatedWebhook = self::createHttpRequestAndSend($apiLoginId, $transactionKey, $updateUrl, 'PUT', $json);
            if (!is_array($updatedWebhook) || !isset($updatedWebhook['webhookId'])) {
                throw new \Exception('Webhook cannot be updated');
            }
        }
    }

    /**
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @param string $opaqueDataJson
     * @param array $inputs
     * @return ChargeBaseResult|ChargeResult
     * @throws \Exception
     */
    public static function charge($purchaseRequest, $purchase, $opaqueDataJson, array $inputs)
    {
        self::autoload();

        $paymentProfile = $purchaseRequest->PaymentProfile;
        if ($paymentProfile === null) {
            throw new \InvalidArgumentException('Payment profile is missing');
        }

        $customerAddress = new AnetAPI\CustomerAddressType();
        $customerAddressHasData = false;
        if (isset($inputs['first_name']) && isset($inputs['last_name'])) {
            $customerAddress->setFirstName($inputs['first_name']);
            $customerAddress->setLastName($inputs['last_name']);
            $customerAddressHasData = true;
        } elseif ($purchase->recurring) {
            // names are required for ARB subscriptions
            $customerAddress->setFirstName('John');
            $customerAddress->setLastName('Appleseed');
            $customerAddressHasData = true;
        }
        if(isset($inputs['phone_number'])){
            $customerAddress->setPhoneNumber($inputs['phone_number']);
            $customerAddressHasData = true;
        }
        if (isset($inputs['address']) && isset($inputs['city']) && isset($inputs['state']) && isset($inputs['zip']) && isset($inputs['country'])) {
            $customerAddress->setAddress($inputs['address']);
            $customerAddress->setCity($inputs['city']);
            $customerAddress->setState($inputs['state']);
            $customerAddress->setZip($inputs['zip']);
            $customerAddress->setCountry($inputs['country']);
            $customerAddressHasData = true;
        }

        $customerData = new AnetAPI\CustomerDataType();
        $customerDataHasData = false;
        $visitor = \XF::visitor();
        if ($visitor->user_id > 0) {
            $customerData->setId(strval($visitor->user_id));
            $customerDataHasData = true;
        }
        if (isset($inputs['email'])) {
            $customerData->setEmail($inputs['email']);
            $customerDataHasData = true;
        }

        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber(strval($purchaseRequest->purchase_request_id));
        $order->setDescription($purchase->title);

        $opaqueDataArray = @json_decode($opaqueDataJson, true);
        if (!is_array($opaqueDataArray)) {
            throw new \Exception('Opaque Data cannot be decoded');
        }
        $opaqueData = new AnetAPI\OpaqueDataType();
        $opaqueData->setDataDescriptor($opaqueDataArray['dataDescriptor']);
        $opaqueData->setDataValue($opaqueDataArray['dataValue']);

        $payment = new AnetAPI\PaymentType();
        $payment->setOpaqueData($opaqueData);

        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setAmount($purchase->cost);
        if ($purchase->currency !== 'USD') {
            throw new \InvalidArgumentException('Currency is not supported ' . $purchase->currency);
        }
        if ($customerAddressHasData) {
            $transactionRequest->setBillTo($customerAddress);
        }
        if ($customerDataHasData) {
            $transactionRequest->setCustomer($customerData);
        }
        $transactionRequest->setOrder($order);
        $transactionRequest->setPayment($payment);
        $transactionRequest->setTransactionType('authCaptureTransaction');

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransactionRequest($transactionRequest);

        $controller = new AnetController\CreateTransactionController($request);

        /** @var AnetAPI\CreateTransactionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new ChargeResult($apiResponse);
        } else {
            return new ChargeBaseResult($apiResponse);
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param ChargeResult $chargeOk
     * @return BaseResult|CreateCustomerProfileResult
     * @throws \Exception
     */
    public static function createCustomerProfileFromTransaction($paymentProfile, $chargeOk)
    {
        self::autoload();

        $transId = $chargeOk->getTransId();
        if ($transId === null) {
            throw new \LogicException('Charge does not have a valid transaction id');
        }

        $customer = new AnetAPI\CustomerProfileBaseType();
        $customer->setDescription(sprintf('Customer Profile for transaction %s', $chargeOk->getTransId()));

        $request = new AnetApi\CreateCustomerProfileFromTransactionRequest();
        $request->setCustomer($customer);
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransId($transId);

        $controller = new AnetController\CreateCustomerProfileFromTransactionController($request);

        /** @var AnetApi\CreateCustomerProfileResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new CreateCustomerProfileResult($apiResponse);
        } else {
            return new BaseResult($apiResponse);
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param string $transId
     * @return GetTransactionDetailsResult|BaseResult
     * @throws \Exception
     */
    public static function getTransactionDetails($paymentProfile, $transId)
    {
        self::autoload();

        $request = new AnetApi\GetTransactionDetailsRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransId($transId);

        $controller = new AnetController\GetTransactionDetailsController($request);

        /** @var AnetApi\CreateCustomerProfileResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new GetTransactionDetailsResult($apiResponse);
        } else {
            return new BaseResult($apiResponse);
        }
    }

    /**
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @param CreateCustomerProfileResult $customerProfile
     * @param int $attemptId
     * @return BaseResult|SubscribeResult
     * @throws \Exception
     */
    public static function subscribe($purchaseRequest, $purchase, $customerProfile, $attemptId = 0)
    {
        self::autoload();

        self::assertRecurringLength($purchase->lengthAmount, $purchase->lengthUnit);

        $enableLivePayments = !!\XF::config('enableLivePayments');
        $paymentProfile = $purchaseRequest->PaymentProfile;
        if ($paymentProfile === null) {
            throw new \InvalidArgumentException('Payment profile is missing');
        }

        $order = new AnetAPI\OrderType();
        $invoiceNumber = strval($purchaseRequest->purchase_request_id);
        if ($attemptId > 0) {
            $invoiceNumber .= sprintf(':%d', $attemptId);
        }
        $order->setInvoiceNumber($invoiceNumber);
        $order->setDescription($purchase->title);

        $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
        $interval->setLength($purchase->lengthAmount);
        $interval->setUnit($purchase->lengthUnit . 's');

        $startDate = new \DateTime();
        if ($enableLivePayments) {
            $startDate->add(new \DateInterval(sprintf(
                'P%d%s',
                $purchase->lengthAmount,
                strtoupper(substr($purchase->lengthUnit, 0, 1))
            )));
        } else {
            // Sandbox environment: start subscription the next day (for testing)
            $startDate->add(new \DateInterval('P1D'));
        }

        $paymentSchedule = new AnetAPI\PaymentScheduleType();
        $paymentSchedule->setInterval($interval);
        $paymentSchedule->setStartDate($startDate);
        $paymentSchedule->setTotalOccurrences(9999);

        $apiCustomerProfile = new AnetAPI\CustomerProfileIdType();
        $apiCustomerProfile->setCustomerPaymentProfileId($customerProfile->getPaymentProfileId());
        $apiCustomerProfile->setCustomerProfileId($customerProfile->getProfileId());

        $subscription = new AnetAPI\ARBSubscriptionType();
        $subscription->setAmount($purchase->cost);
        if ($purchase->currency !== 'USD') {
            throw new \InvalidArgumentException('Currency is not supported ' . $purchase->currency);
        }
        $subscription->setOrder($order);
        $subscription->setPaymentSchedule($paymentSchedule);
        $subscription->setProfile($apiCustomerProfile);

        $request = new AnetAPI\ARBCreateSubscriptionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setSubscription($subscription);

        $controller = new AnetController\ARBCreateSubscriptionController($request);

        /** @var AnetAPI\ARBCreateSubscriptionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return new SubscribeResult($apiResponse);
        } else {
            $baseResult = new BaseResult($apiResponse);

            $shouldRetry = false;
            $apiMessages = $baseResult->getApiMessages();
            if (isset($apiMessages['E00040'])) {
                $shouldRetry = true;
            }

            if ($shouldRetry && $attemptId < self::SUBSCRIBE_MAX_ATTEMPTS) {
                if ($enableLivePayments) {
                    sleep(1);
                } else {
                    // Sandbox environment is a bit slow. Creating a new subscription immediately after
                    // creating a customer profile may trigger error 40 (The record cannot be found.)
                    sleep(20);
                }

                return self::subscribe($purchaseRequest, $purchase, $customerProfile, $attemptId + 1);
            }

            return $baseResult;
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param string $subscriptionId
     * @return bool
     * @throws \Exception
     */
    public static function unSubscribe($paymentProfile, $subscriptionId)
    {
        self::autoload();

        $request = new AnetAPI\ARBCancelSubscriptionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setSubscriptionId($subscriptionId);

        $controller = new AnetController\ARBCancelSubscriptionController($request);

        /** @var AnetAPI\ARBCancelSubscriptionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller);

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param string $signature
     * @param string $json
     * @return bool
     */
    public static function verifyWebhookSignature($paymentProfile, $signature, $json)
    {
        $expected = 'sha512=' . strtoupper(hash_hmac('sha512', $json, $paymentProfile->options['signature_key']));
        return $signature === $expected;
    }

    /**
     * @return void
     */
    private static function autoload()
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        require(dirname(__DIR__) . '/vendor/autoload.php');
    }

    /**
     * @param int $amount
     * @param string $unit
     * @return void
     * @throws \Exception
     */
    private static function assertRecurringLength($amount, $unit)
    {
        switch ($unit) {
            case 'day':
                if ($amount >= 7 && $amount <= 365) {
                    return;
                }
                break;
            case 'month':
                if ($amount >= 1 && $amount <= 12) {
                    return;
                }
                break;
        }

        throw new \Exception(sprintf('Recurring length combination %d %s is not supported', $amount, $unit));
    }

    /**
     * @param AnetController\base\ApiOperationBase $controller
     * @return AnetAPI\ANetApiResponseType
     * @throws \Exception
     */
    private static function chooseEndpointAndExecute($controller)
    {
        if (\XF::$debugMode) {
            $controller->httpClient->setLogFile(File::getTempDir() . '/authorizenet.log');
        }

        $response = $controller->executeWithApiResponse(self::getEndpoint());

        return $response;
    }

    /**
     * @param string $apiLoginId
     * @param string $transactionKey
     * @param string $url
     * @param string $method
     * @param array|null $json
     * @return mixed
     * @throws \Exception
     */
    private static function createHttpRequestAndSend(
        $apiLoginId,
        $transactionKey,
        $url,
        $method = 'GET',
        array $json = null
    ) {
        $client = \XF::app()->http()->client();

        /** @var string $body */
        $body = null;
        /** @var \Exception|null $exception */
        $exception = null;
        /** @var int $statusCode */
        $statusCode = null;

        $options = [
            RequestOptions::AUTH => [$apiLoginId, $transactionKey],
        ];
        if (is_array($json)) {
            $options[RequestOptions::JSON] = $json;
        }

        try {
            $response = $client->request($method, $url, $options);
            $body = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();
            $json = \GuzzleHttp\json_decode($body, true);
        } catch (\Exception $e) {
            $exception = $e;
        } catch (GuzzleException $e) {
            // ignore
            $exception = new \RuntimeException('Unexpected GuzzleException');
        }

        if (\XF::$debugMode) {
            File::log('authorizenet', sprintf(
                '%s $client->%s(%s, %s): %d %s',
                __METHOD__,
                $method,
                $url,
                json_encode($options),
                $statusCode,
                $body
            ));
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $json;
    }

    /**
     * @return string
     */
    private static function getEndpoint()
    {
        if (\XF::config('enableLivePayments')) {
            return AnetConstants\ANetEnvironment::PRODUCTION;
        } else {
            return AnetConstants\ANetEnvironment::SANDBOX;
        }
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @return AnetAPI\MerchantAuthenticationType
     */
    private static function newMerchantAuthentication($paymentProfile)
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($paymentProfile->options['api_login_id']);
        $merchantAuthentication->setTransactionKey($paymentProfile->options['transaction_key']);

        return $merchantAuthentication;
    }
}
