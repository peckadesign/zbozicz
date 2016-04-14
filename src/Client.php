<?php
namespace Soukicz\Zbozicz;

use GuzzleHttp\Psr7\Request;
use Soukicz\ArgumentException;
use Soukicz\InputException;
use Soukicz\IOException;

class Client {
    protected $shopId;
    protected $privateKey;
    protected $sandbox;

    function __construct($shopId, $privateKey, $sandbox = false) {
        if(empty($shopId)) {
            throw new ArgumentException('Missing "shopId"');
        }
        if(empty($privateKey)) {
            throw new ArgumentException('Missing "privateKey"');
        }
        $this->shopId = $shopId;
        $this->privateKey = $privateKey;
        $this->sandbox = $sandbox;
    }

    public function isSandbox() {
        return (bool)$this->sandbox;
    }

    /**
     * @param Order $order
     * @return Request
     */
    public function createRequest(Order $order) {
        $errors = $this->validateOrder($order);
        if(!empty($errors)) {
            throw new InputException($errors[0]);
        }
        $data = [
            'PRIVATE_KEY' => $this->privateKey,
            'sandbox' => $this->sandbox,
            'orderId' => $order->getId(),
            'email' => $order->getEmail(),
        ];

        if($order->getDeliveryType()) {
            $data['deliveryType'] = $order->getDeliveryType();
        }

        if($order->getDeliveryDate()) {
            $data['deliveryDate'] = $order->getDeliveryDate()->format('Y-m-d');
        }

        if($order->getDeliveryPrice()) {
            $data['deliveryPrice'] = $order->getDeliveryPrice();
        }

        if($order->getPaymentType()) {
            $data['paymentType'] = $order->getPaymentType();
        }

        if($order->getOtherCosts()) {
            $data['otherCosts'] = $order->getOtherCosts();
        }

        if($order->getTotalPrice()) {
            $data['totalPrice'] = $order->getTotalPrice();
        } else {
            $data['totalPrice'] = $order->getDeliveryPrice() + $order->getOtherCosts();
            foreach ($order->getCartItems() as $cartItem) {
                $data['totalPrice'] += $cartItem->getUnitPrice() * $cartItem->getQuantity();
            }
        }

        if(!empty($order->getCartItems())) {
            $data['cart'] = [];
            foreach ($order->getCartItems() as $cartItem) {
                $item = [];
                if(!empty($cartItem->getId())) {
                    $item['itemId'] = $cartItem->getId();
                }
                if(!empty($cartItem->getName())) {
                    $item['productName'] = $cartItem->getName();
                }
                if(!empty($cartItem->getUnitPrice())) {
                    $item['unitPrice'] = $cartItem->getUnitPrice();
                }
                if(!empty($cartItem->getQuantity())) {
                    $item['quantity'] = $cartItem->getQuantity();
                }
            }
        }

        return new Request(
            'POST',
            $this->getUrl(),
            ['Content-type' => 'application/json'],
            json_encode($data)
        );
    }

    public function sendOrder(Order $order) {
        $request = $this->createRequest($order);
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->getUrl());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request->getHeaders());
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request->getBody());
        $result = curl_exec($ch);
        if($result === false) {
            throw new IOException('Unable to establish connection to ZboziKonverze service: curl error (' . curl_errno($ch) . ') - ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($httpCode !== 200) {
            throw new IOException('Request was not accepted (HTTP ' . $httpCode . ')');
        }
    }

    protected function getUrl() {
        $url = 'https://' . ($this->sandbox ? 'sandbox.zbozi.cz' : 'www.zbozi.cz');

        return $url . '/action/' . $this->shopId . '/conversion/backend';
    }

    /**
     * @param Order $order
     * @return array
     */
    public function validateOrder(Order $order) {
        $errors = [];
        if(empty($order->getId())) {
            $errors[] = 'Missing order code';
        }
        if(empty($order->getEmail())) {
            $errors[] = 'Missing email address';
        }
        return $errors;
    }
}