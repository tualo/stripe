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
}
