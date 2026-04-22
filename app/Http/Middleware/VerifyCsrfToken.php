<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * CSRF verification for web routes. Exceptions are for local/testing API calls only.
 */
class VerifyCsrfToken extends ValidateCsrfToken
{
    /**
     * URIs excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'admin/plans/*/features',
        'payment/success',
        'payment/failure',
        'payments/payu/success',
        'payments/payu/failure',
        'payments/payu/webhook',
    ];
}
