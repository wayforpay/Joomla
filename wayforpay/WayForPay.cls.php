<?php

class WayForPay
{
    const ORDER_APPROVED = 'Approved';

    const ORDER_SEPARATOR = '#';

    const SIGNATURE_SEPARATOR = ';';

    protected $secret_key = '';

    const URL = "https://secure.wayforpay.com/pay/";
    protected $keysForResponseSignature = array(
        'merchantAccount',
        'orderReference',
        'amount',
        'currency',
        'authCode',
        'cardPan',
        'transactionStatus',
        'reasonCode'
    );

    /** @var array */
    protected $keysForSignature = array(
        'merchantAccount',
        'merchantDomainName',
        'orderReference',
        'orderDate',
        'amount',
        'currency',
        'productName',
        'productCount',
        'productPrice'
    );


    /**
     * @param $option
     * @param $keys
     * @return string
     */
    public function getSignature($option, $keys)
    {
        $hash = array();
        foreach ($keys as $dataKey) {
            if (!isset($option[$dataKey])) {
                continue;
            }
            if (is_array($option[$dataKey])) {
                foreach ($option[$dataKey] as $v) {
                    $hash[] = $v;
                }
            } else {
                $hash [] = $option[$dataKey];
            }
        }

        $hash = implode(self::SIGNATURE_SEPARATOR, $hash);
        return hash_hmac('md5', $hash, $this->getSecretKey());
    }


    /**
     * @param $options
     * @return string
     */
    public function getRequestSignature($options)
    {
        return $this->getSignature($options, $this->keysForSignature);
    }

    /**
     * @param $options
     * @return string
     */
    public function getResponseSignature($options)
    {
        return $this->getSignature($options, $this->keysForResponseSignature);
    }


    /**
     * @param array $data
     * @return string
     */
    public function getAnswerToGateWay($data)
    {
        $time = time();
        $responseToGateway = array(
            'orderReference' => $data['orderReference'],
            'status' => 'accept',
            'time' => $time
        );
        $sign = array();
        foreach ($responseToGateway as $dataKey => $dataValue) {
            $sign [] = $dataValue;
        }
        $sign = implode(self::SIGNATURE_SEPARATOR, $sign);
        $sign = hash_hmac('md5', $sign, $this->getSecretKey());
        $responseToGateway['signature'] = $sign;

        return json_encode($responseToGateway);
    }

    /**
     * @param $response
     * @return bool|string
     */
    public function isPaymentValid($response)
    {
        $sign = $this->getResponseSignature($response);

        if ($sign != $response['merchantSignature']) {
            return 'An error has occurred during payment. Signature is not valid.';
        }

        if ($response['transactionStatus'] != self::ORDER_APPROVED) {
            return false;
        }

        return true;
    }


    public function setSecretKey($key)
    {
        $this->secret_key = $key;
    }

    protected function getSecretKey()
    {
        return $this->secret_key;
    }
}
