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
    public const RESPONSE_CODE_TRANSACTION_APPROVED = '1';
    public const RESPONSE_OK = 'Ok';

    public const SUBSCRIBE_MAX_ATTEMPTS = 3;

    public const WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE = 'net.authorize.payment.authcapture.created';
    public const WEBHOOK_EVENT_TYPE_REFUND = 'net.authorize.payment.refund.created';
    public const WEBHOOK_EVENT_TYPE_VOID = 'net.authorize.payment.void.created';

    /**
     * Transaction statuses that have completed settlement and can therefore be
     * refunded directly (including partial refunds).
     */
    public const REFUNDABLE_STATUSES = ['settledSuccessfully'];

    /**
     * Transaction statuses that are still awaiting the daily settlement batch.
     * Authorize.Net cannot refund these — they must be voided instead, and a
     * void can only reverse the full amount, never a partial.
     */
    public const VOIDABLE_STATUSES = [
        'authorizedPendingCapture',
        'capturedPendingSettlement',
        'FDSPendingReview',
        'FDSAuthorizedPendingReview',
        'underReview',
        'approvedReview',
    ];

    /**
     * @param string $apiLoginId
     * @param string $transactionKey
     * @param string $callbackUrl
     * @param bool $live
     * @return void
     * @throws \Exception
     */
    public static function assertWebhookExists($apiLoginId, $transactionKey, $callbackUrl, $live)
    {
        self::autoload();

        $url = self::getEndpoint($live) . '/rest/v1/webhooks';
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
            $updateUrl = self::getEndpoint($live) . $existingWebhook['_links']['self']['href'];
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
        if (isset($inputs['phone_number'])) {
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
        $order->setDescription(self::sanitizeDescription($purchase->title));

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
        self::assertCurrency($paymentProfile, $purchase);
        if ($customerAddressHasData) {
            $transactionRequest->setBillTo($customerAddress);
        }
        if ($customerDataHasData) {
            $transactionRequest->setCustomer($customerData);
        }
        $transactionRequest->setOrder($order);
        $transactionRequest->setPayment($payment);
        $transactionRequest->setTransactionType('authCaptureTransaction');

        $customerIp = self::getIpAddressForAuthorizeNet();
        if ($customerIp !== null) {
            $transactionRequest->setCustomerIp($customerIp);
        }

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransactionRequest($transactionRequest);

        $controller = new AnetController\CreateTransactionController($request);

        /** @var AnetAPI\CreateTransactionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller, self::isLive($paymentProfile));

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
        // Work around an Authorize.Net SDK 2.0.2 bug where the
        // CreateCustomerProfileFromTransactionResponse class is missing: alias it to
        // CreateCustomerProfileResponse so the SDK can resolve the response type.
        if (!class_exists('net\authorize\api\contract\v1\CreateCustomerProfileFromTransactionResponse')) {
            class_alias(
                'net\authorize\api\contract\v1\CreateCustomerProfileResponse',
                'net\authorize\api\contract\v1\CreateCustomerProfileFromTransactionResponse'
            );
        }
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
        $apiResponse = self::chooseEndpointAndExecute($controller, self::isLive($paymentProfile));

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
        $apiResponse = self::chooseEndpointAndExecute($controller, self::isLive($paymentProfile));

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

        $paymentProfile = $purchaseRequest->PaymentProfile;
        if ($paymentProfile === null) {
            throw new \InvalidArgumentException('Payment profile is missing');
        }

        $live = self::isLive($paymentProfile);

        // Wait before the first attempt to ensure the Customer Profile is ready.
        // The sandbox needs extra time for profile propagation; live is ready sooner.
        if ($attemptId === 0 && !$live) {
            sleep(15);
        }

        self::assertRecurringLength($purchase->lengthAmount, $purchase->lengthUnit);

        $order = new AnetAPI\OrderType();
        $invoiceNumber = strval($purchaseRequest->purchase_request_id);
        if ($attemptId > 0) {
            $invoiceNumber .= sprintf(':%d', $attemptId);
        }
        $order->setInvoiceNumber($invoiceNumber);
        $order->setDescription(self::sanitizeDescription($purchase->title));

        $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
        $interval->setLength($purchase->lengthAmount);
        $interval->setUnit($purchase->lengthUnit . 's');

        $startDate = new \DateTime();
        if ($live) {
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
        self::assertCurrency($paymentProfile, $purchase);
        $subscription->setOrder($order);
        $subscription->setPaymentSchedule($paymentSchedule);
        $subscription->setProfile($apiCustomerProfile);

        $request = new AnetAPI\ARBCreateSubscriptionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setSubscription($subscription);

        $controller = new AnetController\ARBCreateSubscriptionController($request);

        /** @var AnetAPI\ARBCreateSubscriptionResponse $apiResponse */
        $apiResponse = self::chooseEndpointAndExecute($controller, $live);

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
                if ($live) {
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
        $apiResponse = self::chooseEndpointAndExecute($controller, self::isLive($paymentProfile));

        if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Reverse a previously captured transaction.
     *
     * Authorize.Net only allows a *refund* once the original transaction has
     * settled (settlement runs roughly once a day). Before that the transaction
     * is still pending and must instead be *voided* — and a void can only reverse
     * the full amount, never a partial. This method inspects the transaction's
     * settlement status and routes to the correct operation:
     *
     *  - settled            -> refund (full or partial)
     *  - pending settlement -> void, but only if this is a full reversal;
     *                          a partial refund before settlement is rejected
     *                          with a clear message so the admin can void the
     *                          full amount now or refund after it settles
     *  - anything else      -> rejected with the gateway status surfaced
     *
     * @param PaymentProfile $paymentProfile
     * @param string $transId The original transaction ID to reverse
     * @param float $amount The refund amount
     * @return array{success: bool, provider_refund_id?: string, voided?: bool, error?: string}
     */
    public static function refund(PaymentProfile $paymentProfile, string $transId, float $amount): array
    {
        self::autoload();

        // Settlement status decides refund-vs-void; the details also carry the
        // card number a refund needs, so we always fetch them up front.
        $transactionDetails = self::getTransactionDetails($paymentProfile, $transId);
        if (!$transactionDetails->isOk()) {
            return [
                'success' => false,
                'error' => 'Could not retrieve original transaction details: '
                    . implode(', ', $transactionDetails->getApiMessages()),
            ];
        }

        $status = $transactionDetails->getTransactionStatus();

        if (in_array($status, self::REFUNDABLE_STATUSES, true)) {
            return self::refundSettledTransaction($paymentProfile, $transId, $amount, $transactionDetails);
        }

        if (in_array($status, self::VOIDABLE_STATUSES, true)) {
            $originalAmount = $transactionDetails->getTransactionAmount();
            $isFullReversal = $originalAmount === null || $amount >= ($originalAmount - 0.001);

            if (!$isFullReversal) {
                return [
                    'success' => false,
                    'error' => sprintf(
                        'This payment has not settled yet (status: %s). Authorize.Net cannot issue a '
                        . 'partial refund before settlement — void the full amount now, or issue the '
                        . 'partial refund once the transaction settles (usually within 24 hours).',
                        $status
                    ),
                ];
            }

            return self::void($paymentProfile, $transId);
        }

        // voided, already refunded, declined, expired, etc. — not actionable.
        // Surface the status instead of firing an API call we know will fail.
        return [
            'success' => false,
            'error' => sprintf(
                'This transaction cannot be refunded or voided in its current state (status: %s).',
                $status !== null && $status !== '' ? $status : 'unknown'
            ),
        ];
    }

    /**
     * Refund a settled transaction. Authorize.Net requires the original
     * transaction ID and the last 4 digits of the card, which we read from the
     * already-fetched transaction details.
     *
     * @return array{success: bool, provider_refund_id?: string, error?: string}
     */
    protected static function refundSettledTransaction(
        PaymentProfile $paymentProfile,
        string $transId,
        float $amount,
        GetTransactionDetailsResult $transactionDetails
    ): array {
        $cardNumber = $transactionDetails->getMaskedCardNumber();
        if (!$cardNumber) {
            return [
                'success' => false,
                'error' => 'Could not determine the card number from the original transaction.',
            ];
        }

        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($cardNumber);
        $creditCard->setExpirationDate('XXXX');

        $payment = new AnetAPI\PaymentType();
        $payment->setCreditCard($creditCard);

        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType('refundTransaction');
        $transactionRequest->setAmount($amount);
        $transactionRequest->setPayment($payment);
        $transactionRequest->setRefTransId($transId);

        // Carry the original transaction's invoice number onto the refund so it
        // stays traceable in the Authorize.Net merchant interface; without an
        // order element the refund shows with no invoice number.
        $invoiceNumber = $transactionDetails->getInvoiceNumber();
        if ($invoiceNumber !== null && $invoiceNumber !== '') {
            $order = new AnetAPI\OrderType();
            $order->setInvoiceNumber($invoiceNumber);
            $transactionRequest->setOrder($order);
        }

        return self::executeTransactionRequest($paymentProfile, $transactionRequest, 'Refund transaction was not approved.');
    }

    /**
     * Void an unsettled transaction. This reverses the full amount; Authorize.Net
     * does not support partial voids.
     *
     * @return array{success: bool, provider_refund_id?: string, voided?: bool, error?: string}
     */
    public static function void(PaymentProfile $paymentProfile, string $transId): array
    {
        self::autoload();

        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType('voidTransaction');
        $transactionRequest->setRefTransId($transId);

        $result = self::executeTransactionRequest($paymentProfile, $transactionRequest, 'Void transaction was not approved.');
        if (!empty($result['success'])) {
            $result['voided'] = true;
        }

        return $result;
    }

    /**
     * Send a CreateTransactionRequest (refund or void) and normalise the response
     * into the shared result shape. Keeps the gateway/API-message handling in one
     * place so both refunds and voids surface errors identically.
     *
     * @return array{success: bool, provider_refund_id?: string, error?: string}
     */
    protected static function executeTransactionRequest(
        PaymentProfile $paymentProfile,
        AnetAPI\TransactionRequestType $transactionRequest,
        string $notApprovedError
    ): array {
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication(self::newMerchantAuthentication($paymentProfile));
        $request->setTransactionRequest($transactionRequest);

        $controller = new AnetController\CreateTransactionController($request);

        try {
            /** @var AnetAPI\CreateTransactionResponse $apiResponse */
            $apiResponse = self::chooseEndpointAndExecute($controller, self::isLive($paymentProfile));

            if ($apiResponse->getMessages()->getResultCode() == self::RESPONSE_OK) {
                $transactionResponse = $apiResponse->getTransactionResponse();
                if ($transactionResponse && $transactionResponse->getResponseCode() == self::RESPONSE_CODE_TRANSACTION_APPROVED) {
                    return [
                        'success' => true,
                        'provider_refund_id' => $transactionResponse->getTransId(),
                    ];
                }

                // Transaction not approved
                $errors = [];
                if ($transactionResponse && $transactionResponse->getErrors()) {
                    foreach ($transactionResponse->getErrors() as $error) {
                        $errors[] = $error->getErrorText();
                    }
                }

                return [
                    'success' => false,
                    'error' => $errors ? implode(', ', $errors) : $notApprovedError,
                ];
            }

            $messages = [];
            foreach ($apiResponse->getMessages()->getMessage() as $message) {
                $messages[] = $message->getText();
            }

            return [
                'success' => false,
                'error' => implode(', ', $messages),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Authorize.Net order descriptions must contain only alphanumeric characters
     * plus a small set of punctuation ( . , - _ / ) and spaces. Replace anything
     * else with a space, then collapse runs and trim so the result stays readable.
     */
    public static function sanitizeDescription(?string $description): string
    {
        $description = (string) $description;
        $description = preg_replace('/[^A-Za-z0-9 .,\-_\/]/', ' ', $description);
        $description = preg_replace('/\s+/', ' ', $description);

        return trim($description);
    }

    /**
     * Resolve the currency the payment profile is configured to process, as set
     * by the admin in the payment profile options. Authorize.Net accounts settle
     * in a single currency, so this is the one currency the profile accepts.
     * Defaults to USD when unset (profiles saved before the option existed).
     *
     * @param PaymentProfile $paymentProfile
     * @return string
     */
    public static function getCurrency(PaymentProfile $paymentProfile): string
    {
        $currency = $paymentProfile->options['currency'] ?? '';

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
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
     * Ensure the purchase's currency matches the one the payment profile is
     * configured to process. Authorize.Net accounts settle in a single currency,
     * so a mismatch can never succeed and is rejected up front.
     *
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function assertCurrency($paymentProfile, $purchase)
    {
        $currency = self::getCurrency($paymentProfile);
        if ($purchase->currency !== $currency) {
            throw new \InvalidArgumentException(sprintf(
                'Currency %s is not supported by this payment profile (expected %s)',
                $purchase->currency,
                $currency
            ));
        }
    }

    /**
     * @param AnetController\base\ApiOperationBase $controller
     * @param bool $live
     * @return AnetAPI\ANetApiResponseType
     * @throws \Exception
     */
    private static function chooseEndpointAndExecute($controller, $live)
    {
        if (\XF::$debugMode) {
            $controller->httpClient->setLogFile(File::getTempDir() . '/authorizenet.log');
        }

        $response = $controller->executeWithApiResponse(self::getEndpoint($live));

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
        ?array $json = null
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
     * Whether the given payment profile transacts against Authorize.Net's live
     * (production) environment. Controlled per profile via the "environment"
     * option in the admin control panel.
     *
     * The legacy global \XF::config('enableLivePayments') flag is read once, at
     * upgrade time, and migrated into this option (see Setup::upgrade1050500Step1);
     * verifyConfig() also normalises it on every save. A configured profile
     * therefore always carries an explicit value, and anything that is not an
     * explicit "production" selection is treated as the safe sandbox default.
     *
     * @param PaymentProfile $paymentProfile
     * @return bool
     */
    public static function isLive(PaymentProfile $paymentProfile): bool
    {
        return ($paymentProfile->options['environment'] ?? 'sandbox') === 'production';
    }

    /**
     * @param bool $live
     * @return string
     */
    private static function getEndpoint($live)
    {
        return $live
            ? AnetConstants\ANetEnvironment::PRODUCTION
            : AnetConstants\ANetEnvironment::SANDBOX;
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

    /**
     * Get customer IP address formatted for Authorize.Net API.
     * Authorize.Net limits customerIp to 15 characters (IPv4 only),
     * so IPv6 addresses cannot be used and will return null.
     *
     * @return string|null Returns IPv4 address or null if IPv6/unavailable
     */
    private static function getIpAddressForAuthorizeNet()
    {
        $ip = \XF::app()->request()->getIp();

        if (empty($ip)) {
            return null;
        }

        // Check if it's already a valid IPv4 (max 15 chars: 255.255.255.255)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }

        // Check for IPv6-mapped IPv4 addresses (e.g., ::ffff:192.168.1.1)
        if (preg_match('/^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $ip, $matches)) {
            $extractedIp = $matches[1];
            if (filter_var($extractedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $extractedIp;
            }
        }

        // Pure IPv6 address - cannot be used with Authorize.Net's 15-char limit
        // Return null to skip setting the customer IP (field is optional)
        return null;
    }
}
