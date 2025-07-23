<?php

/*
route zum senden von Geld
*/

namespace Tualo\Office\Stripe\Routes;

use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use Tualo\Office\Stripe\API;
use Tualo\Office\Stripe\SendToBankAccount;

class SendMoney implements IRoute
{

    public static function register()
    {
        BasicRoute::add('/stripe/sendmoney', function ($matches) {
            try {
                $db = App::get('session')->getDB();



                // Stripe API Key (Test-Modus)
                $db = App::get('session')->getDB();
                $stripeSecretKey = $db->singleValue('SELECT val FROM stripe_environment WHERE id="client_secret"', [], 'val');


                $sender = new SendToBankAccount($stripeSecretKey);

                $accountData = [];
                if (file_exists('/home/worldcontact/www/server/temp/stripe_account_data.json')) {

                    $accountData = json_decode(file_get_contents('/home/worldcontact/www/server/temp/stripe_account_data.json'), true);
                }
                $account = $sender->createExpressAccount($accountData);

                echo "Account erstellt: " . $account->id . "\n";

                // 2. Geld senden
                $result = $sender->sendMoney(
                    $account->id,
                    1.50,
                    [
                        'description' => 'Testzahlung 2025',
                        'statement_descriptor' => 'Test 25',
                        'metadata' => [
                            'employee_id' => '12345',
                            'period' => '2025-07'
                        ]
                    ]
                );

                echo "Transfer erfolgreich:\n";
                echo "Transfer ID: " . $result['transfer']->id . "\n";
                echo "Payout ID: " . $result['payout']->id . "\n";
                echo "Betrag: " . $result['amount'] . " EUR\n";

                // 3. Status prüfen
                $status = $sender->checkPayoutStatus($result['payout']->id, $account->id);
                echo "Status: " . $status['status'] . "\n";

                App::result('success', true);
                // App::result('data', $data);
                App::contenttype('application/json');
            } catch (\Exception $e) {
                echo $e->getMessage();
                http_response_code(400);
            }
        }, ['get'], true);
    }
}
