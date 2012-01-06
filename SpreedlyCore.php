<?php

/**
 * Yii extension SpreedlyCore
 *
 * This is a base class for processing Spreedly Core API requests.
 *
 * @author Gary Solomon <gary@wideopentech.com>
 * @version 0.1
 * @creation date: 15-12-2010
 * @filesource SpreedlyCore.php
 *
 * 1. You have to download and upload files to /protected/extensions/spreedly/.
 * 2. Include extension in config/main.php
 *
 */
class SpreedlyCore extends CApplicationComponent
{

    public $apiLogin;
    public $apiSecret;
    public $gatewayToken;

    const METHOD_GET = 'GET';
    const METHOD_PUT = 'PUT';
    const METHOD_POST = 'POST';

    /**
     * Initialize the extension
     */
    public function init()
    {

        if (!function_exists('curl_init'))
            throw new CException(Yii::t('Curl', 'You must have CURL enabled in order to use this extension.'));
    }

    /**
     * Send the charge to Core for processing.
     */
    public function purchase($paymentToken, $amount, $orderId = "", $ipAddress = "")
    {

        $url = 'https://spreedlycore.com/v1/gateways/' . $this->gatewayToken . '/purchase.xml';
        $spreedlyAmount = $amount * 100;
        $xmlIn =
 <<<XML
 <transaction>
    <transaction_type>purchase</transaction_type>
    <payment_method_token>$paymentToken</payment_method_token>
    <amount>$spreedlyAmount</amount>
    <currency_code>USD</currency_code>
    <order_id>$orderId</order_id>
    <ip>$ipAddress</ip>
</transaction>
XML;

        $replyXml = $this->executeCurl($url, SpreedlyCore::METHOD_POST, $xmlIn);
        $reply = array_merge(
            $this->extractHeader($replyXml),
            $this->extractResponse($replyXml),
            $this->extractPaymentMethod($replyXml)
        );

        return $reply;
    }

    /** Sends a credit to Core for processing.
     */
    public function credit($transactionToken, $amount, $ipAddress)
    {

        $url = 'https://spreedlycore.com/v1/transactions/' . $transactionToken . '/credit.xml';
        $spreedlyAmount = $amount * 100;
        $xmlIn =
<<<XML
<transaction>
    <amount>$spreedlyAmount</amount>
</transaction>
XML;

        $replyXml = $this->executeCurl($url, SpreedlyCore::METHOD_POST, $xmlIn);

        $reply = array_merge(
            $this->extractHeader($replyXml),
            $this->extractResponse($replyXml),
            $this->extractPaymentMethod($replyXml)
        );
        return $reply;
    }

    /**
     * Retains the given token in Core.
     */
    public function retainPaymentMethod($paymentToken)
    {

        $url = 'https://spreedlycore.com/v1/payment_methods/' . $paymentToken . '/retain.xml';

        $replyXml = $this->executeCurl($url, SpreedlyCore::METHOD_PUT);

        $reply = array_merge(
            $this->extractHeader($replyXml),
            $this->extractPaymentMethod($replyXml)
        );
        return $reply;
    }

    /**
     * Redacts the given token in Core.
     */
    public function redactPaymentMethod($paymentToken)
    {

        $url = 'https://spreedlycore.com/v1/payment_methods/' . $paymentToken . '/redact.xml';

        $replyXml = $this->executeCurl($url, SpreedlyCore::METHOD_PUT);

        $reply = array_merge(
            $this->extractHeader($replyXml),
            $this->extractPaymentMethod($replyXml)
        );
        return $reply;
    }

    /**
     * Retrieves the given payment method from Core.
     */
    public function retrievePaymentMethod($paymentToken)
    {

        $url = 'https://spreedlycore.com/v1/payment_methods/' . $paymentToken . '.xml';

        $replyXml = $this->executeCurl($url, SpreedlyCore::METHOD_GET);
        $reply = array_merge(
            $this->extractPaymentMethod($replyXml)
        );

        return $reply;
    }

    private function executeCurl($url, $action, $xmlData = "")
    {

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_USERPWD, $this->apiLogin . ':' . $this->apiSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/xml'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        if ($action == SpreedlyCore::METHOD_POST) {

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);
        }
        else if ($action == SpreedlyCore::METHOD_PUT) {

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        $xmlstr = curl_exec($ch);
        curl_close($ch);

        // GET API calls aren't wrapped in an XML transaction, but we're going to force
        // it for consistency in parsing the XML.
        if ($action == SpreedlyCore::METHOD_GET) {

            $xmlstr = "<transaction>" . $xmlstr . "</transaction>";
        }

        // Extract the values from the response XML
        $xml = new SimpleXMLElement(trim($xmlstr));
        return $xml;
    }


    /**
     * Extracts header fields from the response.
     */
    private function extractHeader($xml)
    {

        $reply['transaction_type'] = (string)$xml->transaction_type;
        $reply['token'] = (string)$xml->token;

        if (!empty($xml->error)) {

            $reply['succeeded'] = 0;
            $reply['message'] = (string)$xml->error;
        }
        else {

            $reply['succeeded'] = ((string)$xml->succeeded == 'true') ? 1 : 0;
            $reply['message'] = (string)$xml->message;
        }

        return $reply;
    }

    /**
     * Extracts fields from the "response" element.
     */
    private function extractResponse($xml)
    {

        $reply['response']['success'] = ((string)$xml->response->success == 'true') ? 1 : 0;
        $reply['response']['message'] = (string)$xml->response->message;
        $reply['response']['error_code'] = (string)$xml->response->error_code;
        $reply['response']['avs_code'] = (string)$xml->response->avs_code;
        $reply['response']['avs_message'] = (string)$xml->response->avs_message;
        $reply['response']['cvv_code'] = (string)$xml->response->cvv_code;
        $reply['response']['cvv_message'] = (string)$xml->response->cvv_message;

        return $reply;
    }

    /**
     * Extracts fields from the "paymentMethod" element.
     */
    private function extractPaymentMethod($xml)
    {

        $paymentMethod['payment_method']['token'] = (string)$xml->payment_method->token;
        $paymentMethod['payment_method']['card_type'] = (string)$xml->payment_method->card_type;
        $paymentMethod['payment_method']['card_number'] = (string)$xml->payment_method->number;
        $paymentMethod['payment_method']['card_exp_month'] = (string)$xml->payment_method->month;
        $paymentMethod['payment_method']['card_exp_year'] = (string)$xml->payment_method->year;
        $paymentMethod['payment_method']['data'] = (string)$xml->payment_method->data;

        $errors = array();
        if ($xml->payment_method->errors) {

            foreach ($xml->payment_method->errors->error AS $errorXml) {

                $error = array();
                $error['attribute'] = (string)$errorXml->attributes()->attribute;
                $error['key'] = (string)$errorXml->attributes()->key;
                $error['message'] = (string)$errorXml;

                $errors[] = $error;
            }
            $paymentMethod['payment_method']['errors'] = $errors;
        }
        return $paymentMethod;
    }
}



/*
SAMPLE RESOPNSE:
<transaction> 
  <amount type="integer">9405</amount> 
  <on_test_gateway type="boolean">true</on_test_gateway> 
  <created_at type="datetime">2011-06-15T17:13:24Z</created_at> 
  <updated_at type="datetime">2011-06-15T17:13:24Z</updated_at> 
  <currency_code>USD</currency_code> 
  <succeeded type="boolean">false</succeeded> 
  <token>UdhJ55p7eFBMKyux8Gf0lxYTI3s</token> 
  <message key="messages.failed_response_from_gateway">Unable to obtain a successful response from the gateway.</message> 
  <transaction_type>purchase</transaction_type> 
  <gateway_token>HBwH9D7x7ZZAFCkwDuP8hAxLDA4</gateway_token> 
  <order_id nil="true"></order_id> 
  <response> 
    <success type="boolean">false</success> 
    <message>Unable to process the transaction.</message> 
    <avs_code nil="true"></avs_code> 
    <avs_message nil="true"></avs_message> 
    <cvv_code nil="true"></cvv_code> 
    <cvv_message nil="true"></cvv_message> 
    <error_code></error_code> 
    <error_detail nil="true"></error_detail> 
    <created_at type="datetime">2011-06-15T17:13:24Z</created_at> 
    <updated_at nil="true"></updated_at> 
  </response> 
  <payment_method> 
    <token>H38ii6HR7p4rBGp6pGy6LzClR1r</token> 
    <created_at type="datetime">2011-06-15T17:13:10Z</created_at> 
    <updated_at type="datetime">2011-06-15T17:13:24Z</updated_at> 
    <last_four_digits>1881</last_four_digits> 
    <card_type>visa</card_type> 
    <first_name>Gary</first_name> 
    <last_name>Solomon</last_name> 
    <month type="integer">12</month> 
    <year type="integer">2011</year> 
    <email nil="true"></email> 
    <address1 nil="true"></address1> 
    <address2 nil="true"></address2> 
    <city nil="true"></city> 
    <state nil="true"></state> 
    <zip nil="true"></zip> 
    <country nil="true"></country> 
    <phone_number nil="true"></phone_number> 
    <data nil="true"></data> 
    <payment_method_type>CreditCard</payment_method_type> 
    <verification_value>XXX</verification_value> 
    <number>XXXX-XXXX-XXXX-1881</number> 
    <errors> 
    </errors> 
  </payment_method> 
</transaction> 

*/