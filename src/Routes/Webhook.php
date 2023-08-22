<?php
namespace Tualo\Office\Stripe\Routes;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use Stripe\StripeClient;
use Stripe\Webhook as StripeWebhook;
use Stripe\Exception\SignatureVerificationException;

class Webhook implements IRoute{
    
    public static function register(){
        BasicRoute::add('/stripe/webhook',function($matches){
            try {
                $db = App::get('session')->getDB();

                // The library needs to be configured with your account's secret key.
                // Ensure the key is kept out of any version control system you might be using.
                $stripe = new StripeClient($db->singleValue('SELECT val FROM stripe_environment WHERE id="client_secret"',[],'val'));            

                // This is your Stripe CLI webhook secret for testing your endpoint locally.
                $endpoint_secret = $db->singleValue('SELECT val FROM stripe_environment WHERE id="endpoint_secret"',[],'val');

                $payload = @file_get_contents('php://input');
                $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
                $event = null;

                file_put_contents(App::get('tempPath') . '/.stripe.payload.log',$payload);
                file_put_contents(App::get('tempPath') . '/.stripe.request.log',print_r($_REQUEST,true));
                file_put_contents(App::get('tempPath') . '/.stripe.server.log',print_r($_SERVER,true));

                try {
                    $event = StripeWebhook::constructEvent( $payload, $sig_header, $endpoint_secret );
                } catch(\UnexpectedValueException $e) {
                    // Invalid payload
                    http_response_code(400);
                    echo $e->getMessage();
                    exit();
                } catch(SignatureVerificationException $e) {
                    // Invalid signature
                    echo $e->getMessage();
                    http_response_code(400);
                    exit();
                }

                // Handle the event
                echo 'Received unknown event type ' . $event->type;
                http_response_code(200);
                exit();

            }catch(\Exception $e){
                echo $e->getMessage();
                http_response_code(400);
            }
        },['get','post','put','delete'],true);

    }
}