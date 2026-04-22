<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * PayU India request/response hash helpers (SHA-512).
 *
 * Request preimage (exact pipe layout — no duplicated empty udf segments):
 * key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||SALT
 *
 * Built as: implode('|', [key … udf5]) . '||||||' . salt
 * → 10 pipes inside implode + 6 pipes before salt = 16 pipe characters total.
 *
 * @see https://docs.payu.in/docs/integrate-payu-india
 */
final class PayuHasher
{
    public const EXPECTED_REQUEST_PIPE_COUNT = 16;

    /**
     * Build payment request hash and the exact field values that must appear in the POST body.
     *
     * @return array{
     *     hash: string,
     *     hash_string: string,
     *     amount: string,
     *     key: string,
     *     txnid: string,
     *     productinfo: string,
     *     firstname: string,
     *     email: string,
     *     udf1: string,
     *     udf2: string,
     *     udf3: string,
     *     udf4: string,
     *     udf5: string,
     *     pipe_count: int
     * }
     */
    public static function paymentRequestHash(
        string $key,
        string $txnid,
        string|float $amount,
        string $productinfo,
        string $firstname,
        string $email,
        string $salt,
        string $udf1 = '',
        string $udf2 = '',
        string $udf3 = '',
        string $udf4 = '',
        string $udf5 = '',
    ): array {
        // Step 3: amount MUST be this canonical form everywhere (hash + form). No trim().
        $amount = number_format((float) $amount, 2, '.', '');

        // Only trim firstname; email trim + lowercase (PayU / docs).
        $firstname = trim($firstname);
        $email = strtolower(trim($email));

        // No trim on key, txnid, productinfo, salt, udf — avoid side effects (esp. productinfo / amount).
        $udf1 = (string) $udf1;
        $udf2 = (string) $udf2;
        $udf3 = (string) $udf3;
        $udf4 = (string) $udf4;
        $udf5 = (string) $udf5;

        // Exactly 11 fields, then exactly 6 literal pipes before salt (no extra empty udf slots).
        $string = implode('|', [
            $key,
            $txnid,
            $amount,
            $productinfo,
            $firstname,
            $email,
            $udf1,
            $udf2,
            $udf3,
            $udf4,
            $udf5,
        ]).'||||||'.$salt;

        $pipeCount = substr_count($string, '|');

        Log::info('PAYU_HASH_PREIMAGE_PIPES', [
            'pipe_count' => $pipeCount,
            'expected_pipe_count' => self::EXPECTED_REQUEST_PIPE_COUNT,
            'hash_string' => $string,
        ]);

        if (config('payu.debug_dd_hash_string')) {
            dd($string);
        }

        // PayU payment form expects lowercase hex for the hash in most integrations.
        $hash = strtolower(hash('sha512', $string));

        return [
            'hash' => $hash,
            'hash_string' => $string,
            'amount' => $amount,
            'key' => $key,
            'txnid' => $txnid,
            'productinfo' => $productinfo,
            'firstname' => $firstname,
            'email' => $email,
            'udf1' => $udf1,
            'udf2' => $udf2,
            'udf3' => $udf3,
            'udf4' => $udf4,
            'udf5' => $udf5,
            'pipe_count' => $pipeCount,
        ];
    }

    /**
     * Verify hash returned on success callback (status = success).
     */
    public static function paymentResponseHash(
        string $salt,
        string $status,
        string $email,
        string $firstname,
        string $productinfo,
        string $amount,
        string $txnid,
        string $key,
    ): string {
        // Use callback values verbatim; PayU builds reverse hash from posted fields.
        $seq = $salt.'|'.$status.'|||||||||||'.$email.'|'.$firstname.'|'.$productinfo.'|'.$amount.'|'.$txnid.'|'.$key;

        return strtolower(hash('sha512', $seq));
    }
}
