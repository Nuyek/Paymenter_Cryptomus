<?php

namespace App\Extensions\Gateways\Cryptomus;

use App\Helpers\ExtensionHelper;
use App\Classes\Extensions\Gateway;

use App\Extensions\Gateways\Cryptomus\Api;
use Illuminate\Http\Request;

class Cryptomus extends Gateway
{
    /**
     * Get the extension metadata
     * 
     * @return array
     */
    public function getMetadata()
    {
        return [
            'display_name' => 'Cryptomus',
            'version' => '1.0.0',
            'author' => 'Nuyek, LLC',
            'website' => 'https://nuyek.com',
        ];
    }

    /**
     * Get all the configuration for the extension
     * 
     * @return array
     */
    public function getConfig()
    {
        return [
            [
                'name' => 'payment_key',
                'friendlyName' => 'Payment Key',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'merchant_id',
                'friendlyName' => 'Merchant ID',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    /**
     * Get the URL to redirect to
     * 
     * @param int $total
     * @param array $products
     * @param int $invoiceId
     * @return string
     */
    public function pay($total, $products, $invoiceId)
    {
        include_once __DIR__ . '/vendor/autoload.php';

        $PAYMENT_KEY = ExtensionHelper::getConfig('Cryptomus', 'payment_key');
        $MERCHANT_UUID = ExtensionHelper::getConfig('Cryptomus', 'merchant_id');

        $payment = \Cryptomus\Api\Client::payment($PAYMENT_KEY, $MERCHANT_UUID);

        $data = [
            'amount' => number_format($total, 2, '.', ''),
            'currency' => 'USD',
            //'network' => 'ETH',
            'order_id' => (string) $invoiceId,
            'url_return' => route('clients.invoice.show', $invoiceId),
            'url_callback' => url('/extensions/cryptomus/webhook'),
            'is_payment_multiple' => false,
            'lifetime' => '7200',
            'is_refresh' => true,
            //'to_currency' => 'ETH'
        ];

        $result = $payment->create($data);

        if ($result == null) {
            ExtensionHelper::debug('Cryptomus', 'Failure to setup payment for invoice ' . $invoiceId);
            return route('clients.invoice.show', $invoiceId);
        }

        if ($result['url'] == null) {
            ExtensionHelper::debug('Cryptomus', 'Failure to setup payment for invoice ' . $invoiceId);
            return route('clients.invoice.show', $invoiceId);
        }

        if (trim($result['url']) == '') {
            ExtensionHelper::debug('Cryptomus', 'Failure to find invoice url for invoice ' . $invoiceId);
            return route('clients.invoice.show', $invoiceId);
        }

        return $result['url'];
    }

    public function webhook(Request $request)
    {

        $input = file_get_contents('php://input');

        if (!isset($input)) {
            return response()->json(['error' => 'Invalid data.'], 422);
        }

        $data = json_decode($input, true);
        
        if (!isset($data)) {
            return response()->json(['error' => 'Invalid data.'], 422);
        }

        $sign = $data['sign'] ?? '';
        unset($data['sign']);
        $paymentStatus = $data['status'];
        $invoiceId = $data['order_id'];

        ExtensionHelper::debug('Cryptomus', 'Attempting payment verification for Cryptomus invoice ' . $invoiceId);

        if ($sign !== md5(base64_encode(json_encode($data)) . ExtensionHelper::getConfig('Cryptomus', 'payment_key'))) {
            ExtensionHelper::debug('Cryptomus', 'Hash verification for Cryptomus invoice ' . $invoiceId . ' failed');
            return response()->json(['error' => 'Not authorized.'], 403);
        }


        if ($paymentStatus === 'paid' || $paymentStatus === 'paid_over') {
            ExtensionHelper::debug('Cryptomus', 'Cryptomus invoice ' . $invoiceId . ' verified and complete');
            ExtensionHelper::paymentDone($invoiceId);
            return response()->json(['success' => true]);
        }

        http_response_code(400);
    }
}
