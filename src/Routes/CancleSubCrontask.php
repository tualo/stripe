<?php
namespace Tualo\Office\Stripe\Routes;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use Stripe\StripeClient;
use Stripe\Webhook as StripeWebhook;
use Stripe\Exception\SignatureVerificationException;
use Tualo\Office\Stripe\API as API;


class CancleSubCrontask implements IRoute{
    
    public static function register(){
        BasicRoute::add('/stripe/canclesubscriptions',function($matches){
            try {
                $db = App::get('session')->getDB();

                /*
                create or replace view view_cancleable_stripe_subscription as 
                select 
                    adressen_stripe_subscriptions.subscription
                from 
                    
                    adressen_stripe_subscriptions 
                    join adressen on (adressen_stripe_subscriptions.kundennummer,adressen_stripe_subscriptions.kostenstelle) = (adressen.kundennummer,adressen.kostenstelle)  
                        and adressen_stripe_subscriptions.cancled = 0
                where abo_cancled=1
                and adressen.abo_gueltig_bis <= curdate() + interval - 1 day
                */
                $liste = $db->direct('select * from view_cancleable_stripe_subscription');

                foreach($liste as $eintrag){
                    $res = API::cancelSubscription($eintrag['subscription']);
                    App::result('success',true);
                    $db->direct('update adressen_stripe_subscriptions set cancled=1 where subscription={subscription}',$eintrag);
                    App::result('data',$res);
                    App::contenttype('application/json');
                }

            }catch(\Exception $e){
                echo $e->getMessage();
                http_response_code(400);
            }

        },['get'],true);

    }
}