<?php
// filepath: /Users/thomashoffmann/Documents/Projects/php/tualo/stripe/src/SendToBankAccount.php

namespace Tualo\Office\Stripe;

use Stripe\Stripe;
use Stripe\Account;
use Stripe\Transfer;
use Stripe\Payout;
use Exception;

/**
 * Klasse zum Senden von Geld an deutsche Bankkonten über Stripe
 * 
 * Basiert auf Stripe Global Payouts API
 * @see https://docs.stripe.com/global-payouts/api-recipient-creation
 */
class SendToBankAccount
{
    private string $secretKey;
    private array $config;

    /**
     * Konstruktor
     * 
     * @param string $secretKey Stripe Secret Key
     * @param bool $testMode Test-Modus aktivieren
     * @param array $config Zusätzliche Konfiguration
     */
    public function __construct(string $secretKey,   array $config = [])
    {
        $this->secretKey = $secretKey;

        $this->config = array_merge([
            'currency' => 'eur',
            'country' => 'DE',
            'default_statement_descriptor' => 'Payout'
        ], $config);

        // Stripe konfigurieren
        Stripe::setApiKey($this->secretKey);
        Stripe::setApiVersion('2023-10-16');
    }

    /**
     * Erstellt einen Express Account für Payouts
     * 
     * @param array $accountData Account-Daten
     * @return Account
     * @throws Exception
     */
    public function createExpressAccount(array $accountData): Account
    {
        try {
            $account = Account::create([
                'type' => 'express',
                'country' => $this->config['country'],
                'email' => $accountData['email'],
                'capabilities' => [
                    'transfers' => ['requested' => true],
                ],
                'business_type' => $accountData['business_type'] ?? 'individual',
                'individual' => $this->buildIndividualData($accountData),
                'external_account' => $this->buildBankAccountData($accountData),
                'tos_acceptance' => [
                    'date' => time(),
                    'ip' => $accountData['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ],
                'settings' => [
                    'payouts' => [
                        'schedule' => [
                            'interval' => 'manual'
                        ]
                    ]
                ]
            ]);

            return $account;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Erstellen des Express Accounts: " . $e->getMessage());
        }
    }

    /**
     * Sendet Geld an ein deutsches Bankkonto
     * 
     * @param string $accountId Stripe Account ID des Empfängers
     * @param float $amount Betrag in Euro
     * @param array $options Zusätzliche Optionen
     * @return array Transfer und Payout Informationen
     * @throws Exception
     */
    public function sendMoney(string $accountId, float $amount, array $options = []): array
    {
        try {
            // Betrag in Cents umwandeln
            $amountInCents = (int) ($amount * 100);

            // Validierung
            $this->validateAmount($amountInCents);
            $this->validateAccount($accountId);

            // 1. Transfer zu Express Account
            $transfer = $this->createTransfer($accountId, $amountInCents, $options);

            // 2. Payout zum Bankkonto
            $payout = $this->createPayout($accountId, $amountInCents, $options);

            return [
                'success' => true,
                'transfer' => $transfer,
                'payout' => $payout,
                'amount' => $amount,
                'currency' => $this->config['currency'],
                'account_id' => $accountId
            ];
        } catch (Exception $e) {
            throw new Exception("Fehler beim Geldversand: " . $e->getMessage());
        }
    }

    /**
     * Erstellt einen Transfer zu einem Express Account
     */
    private function createTransfer(string $accountId, int $amountInCents, array $options): Transfer
    {
        return Transfer::create([
            'amount' => $amountInCents,
            'currency' => $this->config['currency'],
            'destination' => $accountId,
            'description' => $options['description'] ?? 'Payout transfer',
            'metadata' => $options['metadata'] ?? []
        ]);
    }

    /**
     * Erstellt einen Payout vom Express Account zum Bankkonto
     */
    private function createPayout(string $accountId, int $amountInCents, array $options): Payout
    {
        return Payout::create([
            'amount' => $amountInCents,
            'currency' => $this->config['currency'],
            'method' => 'standard',
            'statement_descriptor' => $options['statement_descriptor'] ?? $this->config['default_statement_descriptor'],
            'metadata' => $options['metadata'] ?? []
        ], [
            'stripe_account' => $accountId
        ]);
    }

    /**
     * Baut die Individual-Daten für den Account auf
     */
    private function buildIndividualData(array $accountData): array
    {

        return [
            'first_name' => $accountData['first_name'],
            'last_name' => $accountData['last_name'],
            'email' => $accountData['email'],
            'phone' => $accountData['phone'] ?? null,
            'dob' => [
                'day' => $accountData['birth_day'],
                'month' => $accountData['birth_month'],
                'year' => $accountData['birth_year']
            ],
            'address' => [
                'line1' => $accountData['address_line1'],
                'line2' => $accountData['address_line2'] ?? null,
                'city' => $accountData['city'],
                'postal_code' => $accountData['postal_code'],
                'state' => $accountData['state'] ?? null,
                'country' => $this->config['country']
            ]
        ];
    }

    /**
     * Baut die Bankkonto-Daten auf
     */
    private function buildBankAccountData(array $accountData): array
    {
        return [
            'object' => 'bank_account',
            'country' => $this->config['country'],
            'currency' => $this->config['currency'],
            'account_number' => $accountData['iban'],
            'routing_number' => $accountData['bic'] ?? null, // Für Deutschland optional
            'account_holder_name' => $accountData['account_holder_name']
        ];
    }

    /**
     * Validiert den Betrag
     */
    private function validateAmount(int $amountInCents): void
    {
        if ($amountInCents < 100) { // Minimum 1 EUR
            throw new Exception("Mindestbetrag ist 1.00 EUR");
        }

        if ($amountInCents > 100000000) { // Maximum 1.000.000 EUR
            throw new Exception("Maximalbetrag ist 1.000.000.00 EUR");
        }
    }

    /**
     * Validiert den Account
     */
    private function validateAccount(string $accountId): void
    {
        try {
            $account = Account::retrieve($accountId);

            if (!$account->charges_enabled) {
                throw new Exception("Account ist nicht für Transaktionen aktiviert");
            }

            if (!$account->payouts_enabled) {
                throw new Exception("Account ist nicht für Payouts aktiviert");
            }
        } catch (Exception $e) {
            throw new Exception("Account-Validierung fehlgeschlagen: " . $e->getMessage());
        }
    }

    /**
     * Überprüft den Status eines Payouts
     * 
     * @param string $payoutId Payout ID
     * @param string $accountId Account ID
     * @return array Status-Informationen
     */
    public function checkPayoutStatus(string $payoutId, string $accountId): array
    {
        try {
            $payout = Payout::retrieve($payoutId, [
                'stripe_account' => $accountId
            ]);

            return [
                'id' => $payout->id,
                'status' => $payout->status,
                'amount' => $payout->amount / 100,
                'currency' => $payout->currency,
                'arrival_date' => $payout->arrival_date,
                'description' => $payout->description,
                'failure_code' => $payout->failure_code,
                'failure_message' => $payout->failure_message
            ];
        } catch (Exception $e) {
            throw new Exception("Fehler beim Status-Abruf: " . $e->getMessage());
        }
    }

    /**
     * Listet alle Payouts für einen Account auf
     * 
     * @param string $accountId Account ID
     * @param array $filters Filter-Optionen
     * @return array Liste der Payouts
     */
    public function listPayouts(string $accountId, array $filters = []): array
    {
        try {
            $params = array_merge([
                'limit' => 10
            ], $filters);

            $payouts = Payout::all($params, [
                'stripe_account' => $accountId
            ]);

            $result = [];
            foreach ($payouts->data as $payout) {
                $result[] = [
                    'id' => $payout->id,
                    'status' => $payout->status,
                    'amount' => $payout->amount / 100,
                    'currency' => $payout->currency,
                    'created' => date('Y-m-d H:i:s', $payout->created),
                    'arrival_date' => date('Y-m-d', $payout->arrival_date)
                ];
            }

            return $result;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Abrufen der Payouts: " . $e->getMessage());
        }
    }

    /**
     * Validiert eine deutsche IBAN
     * 
     * @param string $iban IBAN
     * @return bool
     */
    public static function validateGermanIban(string $iban): bool
    {
        // Entferne Leerzeichen und konvertiere zu Großbuchstaben
        $iban = strtoupper(str_replace(' ', '', $iban));

        // Prüfe deutsches IBAN-Format (DE + 20 Zeichen)
        if (!preg_match('/^DE[0-9]{20}$/', $iban)) {
            return false;
        }

        // IBAN Modulo-97 Prüfung
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';

        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];
            if (ctype_alpha($char)) {
                $numeric .= (ord($char) - ord('A') + 10);
            } else {
                $numeric .= $char;
            }
        }

        return bcmod($numeric, '97') === '1';
    }


    /**
     * Erstellt einen Express Account OHNE ToS-Akzeptierung
     * 
     * @param array $accountData Account-Daten
     * @return Account
     * @throws Exception
     */
    public function createExpressAccountWithoutToS(array $accountData): Account
    {
        try {
            $account = Account::create([
                'type' => 'express',
                'country' => $this->config['country'],
                'email' => $accountData['email'],
                'capabilities' => [
                    'transfers' => ['requested' => true],
                ],
                'business_type' => $accountData['business_type'] ?? 'individual',
                'individual' => $this->buildIndividualData($accountData),
                'external_account' => $this->buildBankAccountData($accountData),
                // ToS-Akzeptierung entfernt!
                'settings' => [
                    'payouts' => [
                        'schedule' => [
                            'interval' => 'manual'
                        ]
                    ]
                ]
            ]);

            return $account;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Erstellen des Express Accounts: " . $e->getMessage());
        }
    }

    /**
     * Erstellt einen Onboarding-Link für Account-Verifizierung
     * 
     * @param string $accountId Account ID
     * @return string Onboarding URL
     * @throws Exception
     */
    public function createAccountOnboardingLink(string $accountId): string
    {
        try {
            $accountLink = \Stripe\AccountLink::create([
                'account' => $accountId,
                //'refresh_url' => 'https://yourdomain.com/stripe/refresh',
                'refresh_url' => 'https://world-contact.de/',
                'return_url' => 'https://world-contact.de/',
                'type' => 'account_onboarding',


            ]);

            return $accountLink->url;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Erstellen des Onboarding-Links: " . $e->getMessage());
        }
    }

    /**
     * Prüft den Onboarding-Status eines Accounts
     * 
     * @param string $accountId Account ID
     * @return array Status-Informationen
     */
    public function checkAccountOnboardingStatus(string $accountId): array
    {
        try {
            $account = Account::retrieve($accountId);

            return [
                'details_submitted' => $account->details_submitted,
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
                'requirements' => [
                    'currently_due' => $account->requirements->currently_due ?? [],
                    'eventually_due' => $account->requirements->eventually_due ?? [],
                    'past_due' => $account->requirements->past_due ?? [],
                    'pending_verification' => $account->requirements->pending_verification ?? []
                ]
            ];
        } catch (Exception $e) {
            throw new Exception("Fehler beim Status-Abruf: " . $e->getMessage());
        }
    }

    /**
     * Lädt Testguthaben über eine Charge (nur für Test-Modus)
     * 
     * @param float $amount Betrag in Euro
     * @return \Stripe\Charge
     * @throws Exception
     */
    public function addTestFunds(float $amount): \Stripe\Charge
    {
        try {
            // Betrag in Cents umwandeln
            $amountInCents = (int) ($amount * 100);

            // Test-Kreditkarte für verfügbares Guthaben
            $charge = \Stripe\Charge::create([
                'amount' => $amountInCents,
                'currency' => $this->config['currency'],
                'source' => 'tok_bypassPending', // Spezieller Test-Token für sofortiges Guthaben
                'description' => 'Test funds for payout',
                'metadata' => [
                    'type' => 'test_funding',
                    'purpose' => 'available_balance'
                ]
            ]);

            return $charge;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Laden von Testguthaben: " . $e->getMessage());
        }
    }

    /**
     * Alternative Methode mit der empfohlenen Testkarte
     * 
     * @param float $amount Betrag in Euro
     * @return \Stripe\Charge
     * @throws Exception
     */
    public function addTestFundsWithCardX(float $amount): \Stripe\Charge
    {
        try {
            $amountInCents = (int) ($amount * 100);

            // Erstelle Token mit Test-Kreditkarte 4000000000000077
            $token = \Stripe\Token::create([
                'card' => [
                    'number' => '4000000000000077',
                    'exp_month' => 12,
                    'exp_year' => date('Y') + 2,
                    'cvc' => '123'
                ]
            ]);

            $charge = \Stripe\Charge::create([
                'amount' => $amountInCents,
                'currency' => $this->config['currency'],
                'source' => $token->id,
                'description' => 'Test funds for available balance'
            ]);

            return $charge;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Laden von Testguthaben mit Karte: " . $e->getMessage());
        }
    }

    /**
     * Prüft das verfügbare Guthaben
     * 
     * @return array Guthaben-Informationen
     */
    public function checkAvailableBalance(): array
    {
        try {
            $balance = \Stripe\Balance::retrieve();

            $available = [];
            foreach ($balance->available as $item) {
                $available[] = [
                    'amount' => $item->amount / 100,
                    'currency' => $item->currency,
                    'source_types' => $item->source_types ?? null
                ];
            }

            $pending = [];
            foreach ($balance->pending as $item) {
                $pending[] = [
                    'amount' => $item->amount / 100,
                    'currency' => $item->currency,
                    'source_types' => $item->source_types ?? null
                ];
            }

            return [
                'available' => $available,
                'pending' => $pending,
                'livemode' => $balance->livemode
            ];
        } catch (Exception $e) {
            throw new Exception("Fehler beim Abrufen des Guthabens: " . $e->getMessage());
        }
    }

    /**
     * Lädt Testguthaben mit vorgefertigten Test-Tokens
     * 
     * @param float $amount Betrag in Euro
     * @return \Stripe\Charge
     * @throws Exception
     */
    public function addTestFundsWithCard(float $amount): \Stripe\Charge
    {
        try {
            $amountInCents = (int) ($amount * 100);

            // Verwende vorgefertigte Test-Tokens anstatt direkter Kartennummern
            $testTokens = [
                'tok_visa' => 'Visa ending in 4242',
                'tok_visa_debit' => 'Visa debit ending in 5556',
                'tok_mastercard' => 'Mastercard ending in 4444',
                'tok_amex' => 'American Express ending in 8431',
                'tok_bypassPending' => 'Test card for immediate available balance'
            ];

            // Verwende den Token für sofortiges verfügbares Guthaben
            $selectedToken = 'tok_bypassPending';

            $charge = \Stripe\Charge::create([
                'amount' => $amountInCents,
                'currency' => $this->config['currency'],
                'source' => $selectedToken,
                'description' => 'Test funds for available balance',
                'metadata' => [
                    'type' => 'test_funding',
                    'purpose' => 'available_balance',
                    'token_used' => $selectedToken
                ]
            ]);

            return $charge;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Laden von Testguthaben mit Karte: " . $e->getMessage());
        }
    }

    /**
     * Alternative: Lädt Testguthaben mit PaymentMethod API
     * 
     * @param float $amount Betrag in Euro
     * @return \Stripe\PaymentIntent
     * @throws Exception
     */
    public function addTestFundsWithPaymentMethod(float $amount): \Stripe\PaymentIntent
    {
        try {
            $amountInCents = (int) ($amount * 100);

            // Erstelle PaymentMethod mit Test-Karte
            $paymentMethod = \Stripe\PaymentMethod::create([
                'type' => 'card',
                'card' => [
                    'token' => 'tok_visa' // Vorgefertigter Test-Token
                ]
            ]);

            // Erstelle PaymentIntent
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $this->config['currency'],
                'payment_method' => $paymentMethod->id,
                'confirmation_method' => 'manual',
                'confirm' => true,
                'description' => 'Test funds via PaymentMethod',
                'metadata' => [
                    'type' => 'test_funding',
                    'method' => 'payment_intent'
                ]
            ]);

            return $paymentIntent;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Laden von Testguthaben via PaymentMethod: " . $e->getMessage());
        }
    }

    /**
     * Erstellt einen Charge mit der empfohlenen Testkarte für verfügbares Guthaben
     * 
     * @param float $amount Betrag in Euro
     * @return \Stripe\Charge
     * @throws Exception
     */
    public function addTestFundsDirectBalance(float $amount): \Stripe\Charge
    {
        try {
            $amountInCents = (int) ($amount * 100);

            // Verwende die spezielle Testkarte für sofortiges verfügbares Guthaben
            // Diese Karte fügt Geld direkt zum verfügbaren Guthaben hinzu
            $charge = \Stripe\Charge::create([
                'amount' => $amountInCents,
                'currency' => $this->config['currency'],
                'source' => 'tok_bypassPending', // Spezieller Token für verfügbares Guthaben
                'description' => 'Test funds - immediate available balance',
                'metadata' => [
                    'type' => 'test_funding',
                    'purpose' => 'immediate_available_balance'
                ]
            ]);

            return $charge;
        } catch (Exception $e) {
            throw new Exception("Fehler beim direkten Laden von verfügbarem Testguthaben: " . $e->getMessage());
        }
    }

    /**
     * Erstellt mehrere kleine Charges um Guthaben aufzubauen
     * 
     * @param float $totalAmount Gesamtbetrag in Euro
     * @param int $numberOfCharges Anzahl der Charges
     * @return array Array von Charge-Objekten
     * @throws Exception
     */
    public function addTestFundsMultiple(float $totalAmount, int $numberOfCharges = 3): array
    {
        try {
            $charges = [];
            $amountPerCharge = $totalAmount / $numberOfCharges;
            $amountInCents = (int) ($amountPerCharge * 100);

            $testTokens = [
                'tok_visa',
                'tok_visa_debit',
                'tok_mastercard'
            ];

            for ($i = 0; $i < $numberOfCharges; $i++) {
                $token = $testTokens[$i % count($testTokens)];

                $charge = \Stripe\Charge::create([
                    'amount' => $amountInCents,
                    'currency' => $this->config['currency'],
                    'source' => $token,
                    'description' => "Test funds batch {$i}",
                    'metadata' => [
                        'type' => 'test_funding_batch',
                        'batch_number' => $i,
                        'total_batches' => $numberOfCharges
                    ]
                ]);

                $charges[] = $charge;

                // Kurze Pause zwischen den Charges
                if ($i < $numberOfCharges - 1) {
                    sleep(1);
                }
            }

            return $charges;
        } catch (Exception $e) {
            throw new Exception("Fehler beim Erstellen mehrerer Test-Charges: " . $e->getMessage());
        }
    }
}
