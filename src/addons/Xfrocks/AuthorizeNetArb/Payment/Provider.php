<?php

namespace Xfrocks\AuthorizeNetArb\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PaymentProviderLog;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;
use XF\Repository\Payment;
use Xfrocks\AuthorizeNetArb\Util\Sdk;

class Provider extends AbstractProvider
{
    /**
     * @return string
     */
    public function getCallbackUrl()
    {
        if (\XF::$debugMode) {
            $callbackUrl = \XF::config(__METHOD__);
            if (is_string($callbackUrl)) {
                return $callbackUrl;
            }
        }

        return \XF::app()->options()->boardUrl . '/payment_callback_authorizenet.php';
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function getPaymentResult(CallbackState $state)
    {
        if (isset($state->reversedTransId)) {
            $state->paymentResult = CallbackState::PAYMENT_REVERSED;
            return;
        }

        if (isset($state->eventType) && $state->eventType === Sdk::WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE) {
            $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
            return;
        }
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'Authorize.Net with ARB';
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\View
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $displayCardIcons = false;
        $prefix = 'display_creditcards_';
        $prefixLength = strlen($prefix);
        $acceptedCards = [];
        foreach($purchase->paymentProfile->options as $key => $value){
            if(substr($key, 0, $prefixLength) == $prefix) { 
                if (!in_array($prefix, $acceptedCards)) {
                    $acceptedCards[] = substr($key, $prefixLength);
                }
            }
        }

        $viewParams = [
            'enableLivePayments' => !!\XF::config('enableLivePayments'),
            'purchaseRequest' => $purchaseRequest,
            'paymentProfile' => $purchase->paymentProfile,
            'purchaser' => $purchase->purchaser,
            'purchase' => $purchase,
            'acceptedCards' => $acceptedCards
        ];

        return $controller->view(
            'Xfrocks\AuthorizeNetArb:PaymentInitiate',
            'Xfrocks_AuthorizeNetArb_payment_initiate',
            $viewParams
        );
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function prepareLogData(CallbackState $state)
    {
        if (!isset($state->logDetails)) {
            $state->logDetails = [];
        }

        if (isset($state->inputRaw) && !isset($state->logDetails['inputRaw'])) {
            $state->logDetails['inputRaw'] = $state->inputRaw;
        }

        if (isset($state->apiTransaction) && !isset($state->logDetails['apiTransaction'])) {
            $state->logDetails['apiTransaction'] = $state->apiTransaction;
        }
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param PaymentProfile $paymentProfile
     * @return \XF\Mvc\Reply\AbstractReply|\XF\Mvc\Reply\Error|\XF\Mvc\Reply\Redirect
     * @throws \XF\Mvc\Reply\Exception
     */
    public function processCancellation(
        Controller $controller,
        PurchaseRequest $purchaseRequest,
        PaymentProfile $paymentProfile
    ) {
        $logFinder = \XF::finder('XF:PaymentProviderLog')
            ->where('purchase_request_key', $purchaseRequest->request_key)
            ->where('provider_id', $this->getProviderId())
            ->order('log_date', 'desc');

        $logs = $logFinder->fetch();

        $subscriptionId = null;
        foreach ($logs as $log) {
            if ($log->subscriber_id) {
                $subscriptionId = $log->subscriber_id;
                break;
            }
        }

        if (!$subscriptionId) {
            return $controller->error('Could not find a subscriber ID or customer ID for this purchase request.');
        }

        $unSubscribed = false;
        try {
            $unSubscribed = Sdk::unSubscribe($paymentProfile, $subscriptionId);
        } catch (\Exception $e) {
            \XF::logException($e);
        }
        if (!$unSubscribed) {
            throw $controller->exception($controller->error(
                \XF::phrase('this_subscription_cannot_be_cancelled_maybe_already_cancelled')
            ));
        }

        return $controller->redirect(
            $controller->getDynamicRedirect(),
            \XF::phrase('Xfrocks_AuthorizeNetArb_subscription_cancelled_successfully')
        );
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\Redirect|null
     * @throws \XF\Mvc\Reply\Exception
     * @throws \Exception
     */
    public function processPayment(
        Controller $controller,
        PurchaseRequest $purchaseRequest,
        PaymentProfile $paymentProfile,
        Purchase $purchase
    ) {
        $ppOptions = $paymentProfile->options;
        $opaqueDataJson = $controller->filter('opaque_data', 'str');
        if ($opaqueDataJson === '') {
            throw $controller->exception($controller->error(\XF::phrase('something_went_wrong_please_try_again')));
        }

        $inputFilters = [];
        if (isset($ppOptions['require_names']) && !!$ppOptions['require_names']) {
            $inputFilters['first_name'] = 'str';
            $inputFilters['last_name'] = 'str';
        }
        if (isset($ppOptions['require_email']) && !!$ppOptions['require_email']) {
            $inputFilters['email'] = 'str';
        }
        if (isset($ppOptions['require_address']) && !!$ppOptions['require_address']) {
            $inputFilters['address'] = 'str';
            $inputFilters['city'] = 'str';
            $inputFilters['state'] = 'str';
            $inputFilters['zip'] = 'str';
            $inputFilters['country'] = 'str';
        }
        $inputs = $controller->filter($inputFilters);
        foreach (array_keys($inputFilters) as $inputKey) {
            if (strlen($inputs[$inputKey]) > 0) {
                continue;
            }

            switch ($inputKey) {
                case 'email':
                    $fieldPhrase = \XF::phrase($inputKey);
                    break;
                default:
                    $fieldPhrase = \XF::phrase('Xfrocks_AuthorizeNetArb_' . $inputKey);
            }

            $phrase = \XF::phrase('please_enter_value_for_required_field_x', ['field' => $fieldPhrase]);
            throw $controller->exception($controller->error($phrase));
        }

        $chargeResult = Sdk::charge($purchaseRequest, $purchase, $opaqueDataJson, $inputs);

        $chargeOk = ($chargeResult->isOk() && $chargeResult->getResponseCode() === Sdk::RESPONSE_CODE_TRANSACTION_APPROVED);
        $chargeLogType = $chargeOk ? 'info' : 'error';
        /** @var Payment $paymentRepo */
        $paymentRepo = \XF::repository('XF:Payment');
        $paymentRepo->logCallback(
            $purchaseRequest->request_key,
            $this->getProviderId(),
            $chargeResult->getTransId(),
            $chargeLogType,
            'Authorize.Net charge ' . $chargeLogType,
            ['charge' => $chargeResult->toArray()]
        );

        if (!$chargeOk) {
            $chargeErrors = $chargeResult->getTransactionErrors();
            if (count($chargeErrors) > 0) {
                $errorPhrase = \XF::phrase('Xfrocks_AuthorizeNetArb_charge_errors_x', [
                    'errors' => implode('</li><li>', $chargeErrors)
                ]);
            } else {
                $errorPhrase = \XF::phrase('something_went_wrong_please_try_again');
            }

            throw $controller->exception($controller->error($errorPhrase));
        }
        /** @var Sdk\ChargeResult $chargeResultOk */
        $chargeResultOk = $chargeResult;

        if ($purchase->recurring) {
            $subscribeLogType = 'error';
            $subscribeLogDetails = [];
            $subscribeLogSubId = null;

            try {
                $customerProfile = Sdk::createCustomerProfileFromTransaction($paymentProfile, $chargeResultOk);
                $subscribeLogDetails['customerProfile'] = $customerProfile->toArray();

                if ($customerProfile->isOk()) {
                    /** @var Sdk\CreateCustomerProfileResult $customerProfileOk */
                    $customerProfileOk = $customerProfile;
                    $subscribeResult = Sdk::subscribe($purchaseRequest, $purchase, $customerProfileOk);
                    $subscribeLogDetails['subscribe'] = $subscribeResult->toArray();

                    if ($subscribeResult->isOk()) {
                        /** @var Sdk\SubscribeResult $subscribeResultOk */
                        $subscribeResultOk = $subscribeResult;
                        $subscribeLogSubId = $subscribeResultOk->getSubscriptionId();
                        $subscribeLogType = 'info';
                    }
                }
            } catch (\Exception $e) {
                \XF::logException($e, false, '', true);
            }

            $paymentRepo->logCallback(
                $purchaseRequest->request_key,
                $this->getProviderId(),
                $chargeResult->getTransId(),
                $subscribeLogType,
                'Authorize.Net subscribe ' . $subscribeLogType,
                $subscribeLogDetails,
                $subscribeLogSubId
            );
        }

        return $controller->redirect($purchase->returnUrl);
    }

    /**
     * @param \XF\Entity\UserUpgradeActive $active
     * @return string
     */
    public function renderCancellation(\XF\Entity\UserUpgradeActive $active)
    {
        $data = [
            'active' => $active,
            'purchaseRequest' => $active->PurchaseRequest
        ];

        return \XF::app()->templater()->renderTemplate(
            'public:Xfrocks_AuthorizeNetArb_payment_cancel_recurring',
            $data
        );
    }

    /**
     * @param \XF\Http\Request $request
     * @return CallbackState
     */
    public function setupCallback(\XF\Http\Request $request)
    {
        $state = new CallbackState();

        $headerXAnetSignatureKey = 'X-ANET-Signature';
        $headerXAnetSignatureKey = str_replace('-', '_', $headerXAnetSignatureKey);
        $headerXAnetSignatureKey = strtoupper($headerXAnetSignatureKey);
        /** @noinspection PhpUndefinedFieldInspection */
        $state->headerXAnetSignature = $request->getServer('HTTP_' . $headerXAnetSignatureKey);

        /** @noinspection PhpUndefinedFieldInspection */
        $state->inputRaw = $inputRaw = $request->getInputRaw();
        $input = @json_decode($inputRaw, true);
        $filtered = \XF::app()->inputFilterer()->filterArray(is_array($input) ? $input : [], [
            'eventType' => 'str',
            'payload' => 'array',
        ]);

        /** @noinspection PhpUndefinedFieldInspection */
        $state->eventType = $filtered['eventType'];

        if (!isset($filtered['payload']['entityName'])) {
            return $state;
        }
        switch ($filtered['payload']['entityName']) {
            case 'transaction':
                if (isset($filtered['payload']['authAmount'])) {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $state->authAmount = $filtered['payload']['authAmount'];
                }

                $state->transactionId = $filtered['payload']['id'];
                break;
        }

        return $state;
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param mixed $currencyCode
     * @return bool
     */
    public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode)
    {
        return $currencyCode === 'USD';
    }

    /**
     * @param CallbackState $state
     * @return bool
     * @throws \Exception
     */
    public function validateCallback(CallbackState $state)
    {
        /** @var Payment $paymentRepo */
        $paymentRepo = \XF::repository('XF:Payment');

        $allPaymentProfiles = $paymentRepo->finder('XF:PaymentProfile')
            ->where('active', true)
            ->where('provider_id', $this->getProviderId());
        $paymentProfile = null;

        foreach ($allPaymentProfiles as $_paymentProfile) {
            /** @noinspection PhpUndefinedFieldInspection */
            if (Sdk::verifyWebhookSignature($_paymentProfile, $state->headerXAnetSignature, $state->inputRaw)) {
                $paymentProfile = $_paymentProfile;
                break;
            }
        }

        if ($paymentProfile === null) {
            $state->logType = 'error';
            $state->logMessage = 'Webhook data cannot be trusted / verified.';

            // this is required for webhook creation
            $state->httpCode = 200;

            return false;
        }
        $state->paymentProfile = $paymentProfile;

        if ($state->transactionId && !$state->requestKey) {
            $infoLogs = $paymentRepo->findLogsByTransactionId($state->transactionId, 'info');
            foreach ($infoLogs as $infoLog) {
                if ($infoLog->provider_id !== $this->getProviderId()) {
                    continue;
                }

                $state->requestKey = $infoLog->purchase_request_key;

                if (isset($state->eventType) && $state->eventType === Sdk::WEBHOOK_EVENT_TYPE_VOID) {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $state->reversedTransId = $state->transactionId;
                    $state->transactionId .= ':voided';
                }
            }
        }

        if ($state->transactionId && !$state->requestKey) {
            $transaction = Sdk::getTransactionDetails($paymentProfile, $state->transactionId);
            if ($transaction->isOk()) {
                /** @noinspection PhpUndefinedFieldInspection */
                $state->apiTransaction = $transaction->toArray();
                /** @var Sdk\GetTransactionDetailsResult $transactionOk */
                $transactionOk = $transaction;

                $subscriptionId = $transactionOk->getSubscriptionId();
                if ($subscriptionId != null) {
                    $state->subscriberId = $subscriptionId;

                    /** @var PaymentProviderLog|null $providerLog */
                    $providerLog = \XF::em()->findOne('XF:PaymentProviderLog', [
                        'subscriber_id' => $subscriptionId,
                        'provider_id' => $this->getProviderId()
                    ]);
                    if ($providerLog !== null) {
                        $state->requestKey = $providerLog->purchase_request_key;
                    }
                }

                if (!$state->requestKey) {
                    $reversedTransId = $transactionOk->getReversedTransId();
                    if ($reversedTransId != null) {
                        $infoLogs = $paymentRepo->findLogsByTransactionId($reversedTransId, 'info');
                        foreach ($infoLogs as $infoLog) {
                            if ($infoLog->provider_id !== $this->getProviderId()) {
                                continue;
                            }

                            /** @noinspection PhpUndefinedFieldInspection */
                            $state->reversedTransId = $reversedTransId;

                            $state->requestKey = $infoLog->purchase_request_key;
                        }
                    }
                }

                if (!$state->requestKey) {
                    // Authorize.Net does not mark the first transaction for ARB as recurring billing
                    // we have to rely on the invoice number to process it
                    $invoiceNumber = $transactionOk->getInvoiceNumber();
                    if (preg_match('/^(\d+)(:\d+)?$/', strval($invoiceNumber), $matches) === 1) {
                        $purchaseRequestId = $matches[1];

                        /** @var PurchaseRequest|null $purchaseRequest */
                        $purchaseRequest = \XF::em()->findOne('XF:PurchaseRequest', [
                            'purchase_request_id' => $purchaseRequestId,
                            'payment_profile_id' => $state->paymentProfile->payment_profile_id,
                        ]);

                        if ($purchaseRequest !== null) {
                            $state->purchaseRequest = $purchaseRequest;
                        }
                    }
                }
            }
        }

        if (!$state->getPurchaseRequest()) {
            $state->logType = 'error';
            $state->logMessage = 'Purchase request cannot be detected.';

            // this is required to avoid webhook being disabled
            $state->httpCode = 200;

            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCost(CallbackState $state)
    {
        if (!isset($state->eventType)) {
            $state->logType = 'error';
            $state->logMessage = 'Missing event type';
            return false;
        }

        switch ($state->eventType) {
            case Sdk::WEBHOOK_EVENT_TYPE_AUTH_AND_CAPTURE:
                if (!isset($state->authAmount)) {
                    $state->logType = 'error';
                    $state->logMessage = 'Missing auth amount';
                    return false;
                }

                $purchaseRequestAmount = floatval($state->getPurchaseRequest()->cost_amount);
                $amountDelta = abs($state->authAmount - $purchaseRequestAmount);

                if ($amountDelta > 0.01) {
                    $state->logType = 'error';
                    $state->logMessage = 'Invalid cost amount';
                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * @param array $options
     * @param mixed $errors
     * @return bool
     */
    public function verifyConfig(array &$options, &$errors = [])
    {
        if (!is_array($errors)) {
            $errors = [];
        }

        $requiredOptionKeys = [
            'api_login_id',
            'transaction_key',
            'signature_key',
            'public_client_key'
        ];
        foreach ($requiredOptionKeys as $requiredOptionKey) {
            if (!isset($options[$requiredOptionKey]) || strlen($options[$requiredOptionKey]) === 0) {
                $errors[] = \XF::phrase(
                    'Xfrocks_AuthorizeNetArb_you_must_provide_option_x_to_setup_this_payment',
                    [
                        'option' => \XF::phrase('Xfrocks_AuthorizeNetArb_' . $requiredOptionKey)
                    ]
                );
            }
        }

        try {
            Sdk::assertWebhookExists($options['api_login_id'], $options['transaction_key'], $this->getCallbackUrl());
        } catch (\Exception $e) {
            \XF::logException($e);

            $errors[] = \XF::phrase('Xfrocks_AuthorizeNetArb_cannot_create_webhook', [
                'callbackUrl' => $this->getCallbackUrl()
            ]);
        }

        if (count($errors) > 0) {
            return false;
        }

        return parent::verifyConfig($options, $errors);
    }
}
