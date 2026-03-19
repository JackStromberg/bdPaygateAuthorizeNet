<?php

namespace Xfrocks\AuthorizeNetArb\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PaymentProviderLog;
use XF\Entity\PurchaseRequest;
use XF\Entity\UserUpgradeActive;
use XF\Finder\PaymentProfileFinder;
use XF\Finder\PaymentProviderLogFinder;
use XF\Finder\PurchaseRequestFinder;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;
use XF\Repository\PaymentRepository;
use Xfrocks\AuthorizeNetArb\Util\Sdk;

class Provider extends AbstractProvider
{
    /**
     * @return string
     */
    public function getCallbackUrl()
    {
        // Authorize.net's webhook management API rejects callback URLs containing query
        // string parameters. This shim provides a clean URL that Authorize.net
        // accepts, then injects the _xfProvider parameter and delegates to XenForo's
        // standard payment callback handler.
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
    protected function getSupportedRecurrenceRanges()
    {
        return [
            'day' => [7, 365],
            'month' => [1, 12],
        ];
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\View
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $prefix = 'display_creditcards_';
        $prefixLength = strlen($prefix);
        $acceptedCards = [];
        foreach ($purchase->paymentProfile->options as $key => $value) {
            if (substr($key, 0, $prefixLength) === $prefix) {
                $cardName = substr($key, $prefixLength);
                if (!in_array($cardName, $acceptedCards)) {
                    $acceptedCards[] = $cardName;
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
        $subscriptionId = $this->getSubscriberIdFromPurchaseRequest($purchaseRequest);

        if (!$subscriptionId) {
            return $controller->error(\XF::phrase('could_not_find_subscriber_id_for_this_purchase_request'));
        }

        try {
            $unSubscribed = Sdk::unSubscribe($paymentProfile, $subscriptionId);
        } catch (\Exception $e) {
            \XF::logException($e);
            throw $controller->exception($controller->error(
                \XF::phrase('this_subscription_cannot_be_cancelled_maybe_already_cancelled')
            ));
        }

        if (!$unSubscribed) {
            throw $controller->exception($controller->error(
                \XF::phrase('this_subscription_cannot_be_cancelled_maybe_already_cancelled')
            ));
        }

        $purchasable = $purchaseRequest->Purchasable;
        if ($purchasable && $purchasable->handler) {
            $purchasable->handler->processCancellation($purchaseRequest);
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
        /** @var PaymentRepository $paymentRepo */
        $paymentRepo = \XF::repository(PaymentRepository::class);

        if (!$chargeOk) {
            $paymentRepo->logCallback(
                $purchaseRequest->request_key,
                $this->getProviderId(),
                $chargeResult->getTransId(),
                $chargeLogType,
                'Authorize.Net charge ' . $chargeLogType,
                ['charge' => $chargeResult->toArray()]
            );

            $chargeErrors = $chargeResult->getTransactionErrors();
            if (count($chargeErrors) > 0) {
                $errorPhrase = \XF::phrase('Xfrocks_AuthorizeNetArb_charge_errors_x', [
                    'errors' => implode(', ', $chargeErrors)
                ]);
            } else {
                $errorPhrase = \XF::phrase('something_went_wrong_please_try_again');
            }

            throw $controller->exception($controller->error($errorPhrase));
        }
        /** @var Sdk\ChargeResult $chargeResultOk */
        $chargeResultOk = $chargeResult;
        $subscribeLogSubId = null; // Initialize variable

        if ($purchase->recurring) {
            $subscribeLogType = 'error';
            $subscribeLogDetails = [];

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

            if ($subscribeLogSubId) {
                $purchaseRequest->provider_metadata = json_encode(['subscription' => $subscribeLogSubId]);
                $purchaseRequest->save();
            }
        }
        // Log the charge with the subscriber ID
        $paymentRepo->logCallback(
            $purchaseRequest->request_key,
            $this->getProviderId(),
            $chargeResult->getTransId(),
            $chargeLogType,
            'Authorize.Net charge ' . $chargeLogType,
            ['charge' => $chargeResult->toArray()],
            $subscribeLogSubId // <--- Passes the ID to the upgrade record
        );
        return $controller->redirect($purchase->returnUrl);
    }

    /**
     * @param UserUpgradeActive $active
     * @return string
     */
    public function renderCancellation(UserUpgradeActive $active)
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
        /** @var PaymentRepository $paymentRepo */
        $paymentRepo = \XF::repository(PaymentRepository::class);

        $allPaymentProfiles = \XF::finder(PaymentProfileFinder::class)
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

                    // Try provider_metadata first (preferred), then fall back to logs
                    $purchaseRequest = $this->findPurchaseRequestByMetadata([
                        'subscription' => $subscriptionId,
                    ]);
                    if ($purchaseRequest) {
                        $state->requestKey = $purchaseRequest->request_key;
                    } else {
                        /** @var PaymentProviderLog|null $providerLog */
                        $providerLog = \XF::em()->findOne(PaymentProviderLog::class, [
                            'subscriber_id' => $subscriptionId,
                            'provider_id' => $this->getProviderId()
                        ]);
                        if ($providerLog !== null) {
                            $state->requestKey = $providerLog->purchase_request_key;
                        }
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
                        $purchaseRequest = \XF::em()->findOne(PurchaseRequest::class, [
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

        // --- START FIX: Recovery of Missing Subscriber ID ---
        // If Authorize.Net webhook didn't include the Subscription ID,
        // retrieve it from our logs saved during initial checkout.
        if (!$state->subscriberId) {
            // Get request key from state or fallback to purchase request
            $requestKey = $state->requestKey ?: ($state->purchaseRequest ? $state->purchaseRequest->request_key : null);

            if ($requestKey) {
                $logWithSubId = \XF::finder(PaymentProviderLogFinder::class)
                    ->where('purchase_request_key', $requestKey)
                    ->where('provider_id', $this->getProviderId())
                    ->where('subscriber_id', '!=', null)
                    ->order('log_date', 'desc')
                    ->fetchOne();

                if ($logWithSubId && $logWithSubId->subscriber_id) {
                    $state->subscriberId = $logWithSubId->subscriber_id;
                }
            }
        }
        // --- END FIX ---

        if (!$state->getPurchaseRequest()) {
            $state->logType = 'error';
            $state->logMessage = 'Purchase request cannot be detected.';

            // this is required to avoid webhook being disabled
            $state->httpCode = 200;

            return false;
        }

        // Sync subscription ID to provider_metadata for future lookups
        // (also backfills metadata for subscriptions created before this was added)
        $purchaseRequest = $state->getPurchaseRequest();
        if ($purchaseRequest && $state->subscriberId) {
            $existing = $this->getProviderMetadata($purchaseRequest);
            if (!isset($existing['subscription'])) {
                $existing['subscription'] = $state->subscriberId;
                ksort($existing);
                $purchaseRequest->provider_metadata = json_encode($existing);
                $purchaseRequest->save();
            }
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
    /**
     * VISIBILITY FIX: This forces the Cancel button to render using the default template.
     */
    public function renderCancellationTemplate(PurchaseRequest $purchaseRequest)
    {
        return $this->renderCancellationDefault($purchaseRequest);
    }

    /**
     * Indicates this provider supports admin-initiated refunds.
     * Works with the Jack/PaymentRefund add-on if installed, but does not require it.
     * If Jack/PaymentRefund is not installed, these methods simply exist but are never called.
     */
    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Issue a refund via the Authorize.Net API.
     *
     * @param PaymentProfile $paymentProfile The payment profile with credentials
     * @param PurchaseRequest $purchaseRequest The original purchase request
     * @param string $transactionId The Authorize.Net transaction ID to refund
     * @param float|null $amount Refund amount (null = full refund)
     * @param string $currency Currency code (only USD supported)
     * @return array{success: bool, provider_refund_id?: string, error?: string}
     */
    public function refund(
        PaymentProfile $paymentProfile,
        PurchaseRequest $purchaseRequest,
        string $transactionId,
        ?float $amount = null,
        string $currency = 'USD'
    ): array
    {
        if ($currency !== 'USD')
        {
            return [
                'success' => false,
                'error' => 'Authorize.Net only supports USD refunds.',
            ];
        }

        // Resolve the original transaction ID from the log entry.
        // The transactionId passed in is the event ID from the webhook log,
        // but we need the actual Authorize.Net transaction ID.
        $actualTransId = $this->resolveTransactionId($purchaseRequest, $transactionId);
        if (!$actualTransId)
        {
            return [
                'success' => false,
                'error' => 'Could not determine the Authorize.Net transaction ID.',
            ];
        }

        // If no amount specified, use the full purchase cost
        if ($amount === null)
        {
            $amount = (float) $purchaseRequest->cost_amount;
        }

        return Sdk::refund($paymentProfile, $actualTransId, $amount);
    }

    protected function getProviderMetadata(PurchaseRequest $purchaseRequest): array
    {
        $providerMetadata = json_decode($purchaseRequest->provider_metadata ?? '[]', true);
        if (is_array($providerMetadata)) {
            return $providerMetadata;
        }
        return [];
    }

    protected function findPurchaseRequestByMetadata(array $identifiers): ?PurchaseRequest
    {
        foreach ($identifiers as $key => $value) {
            if (!$value) {
                continue;
            }

            $purchaseRequestFinder = \XF::finder(PurchaseRequestFinder::class);
            $purchaseRequestFinder
                ->where('provider_metadata', 'LIKE', $purchaseRequestFinder->escapeLike(
                    '"' . $key . '":"' . $value . '"',
                    '%?%'
                ));

            $purchaseRequest = $purchaseRequestFinder->fetchOne();
            if ($purchaseRequest) {
                return $purchaseRequest;
            }
        }

        return null;
    }

    protected function getSubscriberIdFromPurchaseRequest(PurchaseRequest $purchaseRequest): ?string
    {
        $providerMetadata = $this->getProviderMetadata($purchaseRequest);

        if (isset($providerMetadata['subscription'])) {
            return $providerMetadata['subscription'];
        }

        // Fallback: search logs (for subscriptions created before provider_metadata was used)
        $logFinder = \XF::finder(PaymentProviderLogFinder::class)
            ->where('purchase_request_key', $purchaseRequest->request_key)
            ->where('provider_id', $this->providerId)
            ->where('subscriber_id', '!=', null)
            ->order('log_date', 'desc');

        $log = $logFinder->fetchOne();

        return $log->subscriber_id ?? null;
    }

    /**
     * Resolve the actual Authorize.Net transaction ID from logs.
     * The charge log stores the real transaction ID in log_details.
     */
    protected function resolveTransactionId(PurchaseRequest $purchaseRequest, string $transactionId): ?string
    {
        // The transactionId on the log entry might already be the Authorize.Net transaction ID
        // Check if it looks like a numeric Authorize.Net transaction ID
        if (is_numeric($transactionId))
        {
            return $transactionId;
        }

        // Fall back to searching the payment log for the charge transaction ID
        $logFinder = \XF::finder(PaymentProviderLogFinder::class)
            ->where('purchase_request_key', $purchaseRequest->request_key)
            ->where('provider_id', $this->getProviderId())
            ->where('log_type', 'info')
            ->order('log_date', 'DESC');

        foreach ($logFinder->fetch() as $log)
        {
            $details = $log->log_details;
            if (isset($details['charge']['_transId']))
            {
                return $details['charge']['_transId'];
            }
        }

        return null;
    }
}
