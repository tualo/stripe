<?php
namespace Tualo\Office\Stripe\Routes;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use Tualo\Office\Stripe\API;

class Test implements IRoute{
    
    public static function register(){
        BasicRoute::add('/stripe/test',function($matches){
            try {
                $db = App::get('session')->getDB();

                $data = API::addShorthandCheckout(
                    'https://tualo.de',
                    'https://tualo.de',
                    'Testprodukt',
                    'Testprodukt Beschreibung',
                    1.99,
                    1
                );
                App::result('success',true);
                App::result('data',$data);
                App::contenttype('application/json');

            }catch(\Exception $e){
                echo $e->getMessage();
                http_response_code(400);
            }
        },['get'],true);

    }
}