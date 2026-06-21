<?php

namespace Xfrocks\AuthorizeNetArb\Util\Sdk;

use net\authorize\api\contract\v1 as AnetAPI;

class ChargeBaseResult extends BaseResult
{
    /**
     * @return string|null
     */
    public function getResponseCode()
    {
        $t = $this->getTransactionResponse();
        if ($t === null) {
            return null;
        }

        return $t->getResponseCode();
    }

    /**
     * @return array
     */
    public function getTransactionErrors()
    {
        $errorTexts = [];

        $t = $this->getTransactionResponse();
        if ($t === null) {
            return $errorTexts;
        }

        //        /** @var AnetAPI\TransactionResponseType\ErrorsAType\ErrorAType[]|null $errors */
        $errors = $t->getErrors();
        if (!is_array($errors)) {
            return $errorTexts;
        }

        foreach ($errors as $error) {
            $errorId = $error->getErrorCode();
            if (isset($errorTexts[$errorId])) {
                $errorId .= sprintf('_%d', count($errorTexts) + 1);
            }

            $errorTexts[$errorId] = $error->getErrorText();
        }

        return $errorTexts;
    }

    /**
     * @return string|null
     */
    public function getTransId()
    {
        $t = $this->getTransactionResponse();
        if ($t === null) {
            return null;
        }

        return $t->getTransId();
    }

    public function toArray()
    {
        $array = self::castToArray($this->getTransactionResponse());

        $array['_apiMessages'] = $this->getApiMessages();
        $array['_transactionErrors'] = $this->getTransactionErrors();

        return $array;
    }

    /**
     * @return AnetAPI\TransactionResponseType|null
     */
    protected function getTransactionResponse()
    {
        /** @var AnetAPI\CreateTransactionResponse|null $apiResponse */
        $apiResponse = $this->apiResponse;

        if ($apiResponse === null) {
            return null;
        }

        return $apiResponse->getTransactionResponse();
    }
}
