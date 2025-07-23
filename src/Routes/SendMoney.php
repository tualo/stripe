<?php
// filepath: /Users/thomashoffmann/Documents/Projects/php/tualo/stripe/src/Routes/SendMoney.php

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

                // Stripe API Key holen
                $stripeSecretKey = $db->singleValue(
                    'SELECT val FROM stripe_environment WHERE id="client_secret"',
                    [],
                    'val'
                );

                $sender = new SendToBankAccount($stripeSecretKey);

                // Account-Daten laden
                $accountData = [];
                $accountDataFile = '/home/worldcontact/www/server/temp/stripe_account_data.json';

                if (file_exists($accountDataFile)) {
                    $accountData = json_decode(file_get_contents($accountDataFile), true);
                } else {
                    throw new \Exception('Account-Daten-Datei nicht gefunden');
                }

                // Prüfe ob bereits ein Account existiert
                $existingAccountId = null;
                if (isset($accountData['stripe_account_id']) && !empty($accountData['stripe_account_id'])) {
                    try {
                        // Versuche existierenden Account abzurufen
                        \Stripe\Stripe::setApiKey($stripeSecretKey);
                        $existingAccount = \Stripe\Account::retrieve($accountData['stripe_account_id']);
                        $existingAccountId = $existingAccount->id;
                        echo "Verwende existierenden Account: " . $existingAccountId . "\n";
                    } catch (\Exception $e) {
                        echo "Existierender Account nicht gefunden, erstelle neuen...\n";
                    }
                }

                if (!$existingAccountId) {
                    // Erstelle Express Account OHNE ToS-Akzeptierung
                    $account = $sender->createExpressAccountWithoutToS($accountData);

                    // Speichere Account ID
                    $accountData['stripe_account_id'] = $account->id;
                    file_put_contents($accountDataFile, json_encode($accountData, JSON_PRETTY_PRINT));

                    echo "Account erstellt: " . $account->id . "\n";
                    echo "Account muss noch verifiziert werden.\n";

                    // Erstelle Onboarding-Link
                    $onboardingLink = $sender->createAccountOnboardingLink($account->id);
                    echo "Onboarding-Link: " . $onboardingLink . "\n";

                    App::result('success', false);
                    App::result('message', 'Account erstellt, aber Onboarding erforderlich');
                    App::result('onboarding_url', $onboardingLink);
                    App::result('account_id', $account->id);
                } else {
                    // Prüfe Account-Status
                    \Stripe\Stripe::setApiKey($stripeSecretKey);
                    $account = \Stripe\Account::retrieve($existingAccountId);

                    if (!$account->details_submitted || !$account->payouts_enabled) {
                        echo "Account ist noch nicht vollständig eingerichtet.\n";

                        // Erstelle neuen Onboarding-Link
                        $onboardingLink = $sender->createAccountOnboardingLink($existingAccountId);

                        App::result('success', false);
                        App::result('message', 'Account-Onboarding noch nicht abgeschlossen');
                        App::result('onboarding_url', $onboardingLink);
                        App::result('account_id', $existingAccountId);
                    } else {
                        // Account ist bereit - sende Geld
                        $result = $sender->sendMoney(
                            $existingAccountId,
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

                        // Status prüfen
                        $status = $sender->checkPayoutStatus($result['payout']->id, $existingAccountId);
                        echo "Status: " . $status['status'] . "\n";

                        App::result('success', true);
                        App::result('transfer_id', $result['transfer']->id);
                        App::result('payout_id', $result['payout']->id);
                        App::result('amount', $result['amount']);
                        App::result('status', $status['status']);
                    }
                }

                App::contenttype('application/json');
            } catch (\Exception $e) {
                echo "Fehler: " . $e->getMessage() . "\n";
                App::result('success', false);
                App::result('error', $e->getMessage());
                App::contenttype('application/json');
                http_response_code(400);
            }
        }, ['get'], true);
    }
}
