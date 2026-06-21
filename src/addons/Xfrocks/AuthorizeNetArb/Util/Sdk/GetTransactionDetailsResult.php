<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class GetTransactionDetailsResult extends BaseResult
{
    public function isOk()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return false;
        }

        return $transaction->getResponseCode() === 1;
    }

    /**
     * @return null|string
     */
    public function getInvoiceNumber()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        /** @var AnetAPI\OrderExType|null $order */
        $order = $transaction->getOrder();
        return $order !== null ? $order->getInvoiceNumber() : null;
    }

    /**
     * @return null|string
     */
    public function getReversedTransId()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        switch ($transaction->getTransactionType()) {
            case 'refundTransaction':
                return $transaction->getRefTransId();
        }

        return null;
    }

    /**
     * Authorize.Net settlement status, e.g. 'settledSuccessfully',
     * 'capturedPendingSettlement'. Used to decide refund vs. void.
     *
     * @return string|null
     */
    public function getTransactionStatus()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        return $transaction->getTransactionStatus();
    }

    /**
     * The amount of the original transaction. Prefers the settled amount, falling
     * back to the authorized amount for transactions that have not settled yet.
     *
     * @return float|null
     */
    public function getTransactionAmount()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        $settleAmount = $transaction->getSettleAmount();
        if ($settleAmount !== null && $settleAmount !== '') {
            return (float) $settleAmount;
        }

        $authAmount = $transaction->getAuthAmount();
        if ($authAmount !== null && $authAmount !== '') {
            return (float) $authAmount;
        }

        return null;
    }

    /**
     * The masked card number of the original transaction (e.g. "XXXX1111").
     * Authorize.Net requires this for a linked (settled) refund. Returns null for
     * non credit-card payments (e.g. eCheck) or if the details are unavailable.
     *
     * @return string|null
     */
    public function getMaskedCardNumber()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        $payment = $transaction->getPayment();
        if ($payment === null) {
            return null;
        }

        $creditCard = $payment->getCreditCard();
        if ($creditCard === null) {
            return null;
        }

        return $creditCard->getCardNumber();
    }

    /**
     * @return int|null
     */
    public function getSubscriptionId()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return null;
        }

        if (!$transaction->getRecurringBilling()) {
            return null;
        }

        /** @var AnetAPI\SubscriptionPaymentType|null $subscription */
        $subscription = $transaction->getSubscription();
        return $subscription !== null ? $transaction->getSubscription()->getId() : null;
    }

    public function toArray()
    {
        $transaction = $this->getTransaction();
        if ($transaction === null) {
            return [];
        }

        $array = self::castToArray($transaction);

        $array['_billTo'] = self::castToArray($transaction->getBillTo());
        $array['_customer'] = self::castToArray($transaction->getCustomer());
        $array['_order'] = self::castToArray($transaction->getOrder());
        $array['_subscription'] = self::castToArray($transaction->getSubscription());

        return $array;
    }

    /**
     * @return AnetAPI\TransactionDetailsType|null
     */
    private function getTransaction()
    {
        /** @var AnetAPI\GetTransactionDetailsResponse|null $getTransactionDetailsResponse */
        $getTransactionDetailsResponse = $this->apiResponse;

        if ($getTransactionDetailsResponse === null) {
            return null;
        }

        return $getTransactionDetailsResponse->getTransaction();
    }
}
