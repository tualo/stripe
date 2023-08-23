<?php
namespace Tualo\Office\Stripe;

use Tualo\Office\Basic\TualoApplication as App;
use Ramsey\Uuid\Uuid;
use Stripe\Stripe;
use Stripe\Checkout;

/**
 * 
 \Tualo\Office\Stripe\API::addShorthandCheckout(
    $success_url,
    $cancel_url,
    $product_name,
    $product_description,
    $amount,
    $quantity
    );
 * 
 */

class API {

    public static function addShorthandCheckout(
        string $success_url,
        string $cancel_url,
        string $product_name,
        string $product_description,
        float $amount,
        int $quantity,
        int $expires_in=3600
    ):array{

        $db = App::get('session')->getDB();
        $stripeSecretKey = $db->singleValue('SELECT val FROM stripe_environment WHERE id="client_secret"',[],'val');
        Stripe::setApiKey($stripeSecretKey);
        $checkout_session = Checkout\Session::create([
            'line_items' => [[
                # Provide the exact Price ID (e.g. pr_1234) of the product you want to sell
                'price_data' => [
                    'currency'=>'eur',
                    'product_data'=>[
                        'name'=>$product_name,
                        'description'=>$product_description
                    ],
                    'unit_amount' => round(100*floatval($amount),0),
                ],
                'quantity' => $quantity,
            ]],
            'mode' => 'payment',
            'success_url' =>    $success_url,
            'cancel_url' =>     $cancel_url,
            'expires_at' =>     $expires_in
        ]);

        return  [
            'url'=>$checkout_session->url,
            'id'=>$checkout_session->id,
            'payment_intent'=>$checkout_session->payment_intent,
            'expires_at'=>$checkout_session->expires_at
        ];
    }
}