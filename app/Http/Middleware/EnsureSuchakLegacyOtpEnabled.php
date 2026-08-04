<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate on the Suchak app's legacy demo/WhatsApp OTP endpoints.
 *
 * Those endpoints can still hand back a plaintext OTP (blueprint §10 S1:
 * MOBILE_OTP_DELIVERY / AdminSetting `mobile_verification_mode = dev_show`), so
 * with real phone verification available they must be OFF in production. They
 * stay switchable because the owner is still testing — but the switch is here,
 * on the server, and the app cannot reach for it.
 *
 * This is the SOLE reader of `firebase_auth.legacy_suchak_otp`.
 *
 * Scope: the four Suchak OTP routes only. The member app's OTP routes are
 * untouched — production currently has no WhatsApp credentials at all, so
 * disabling those would lock members out entirely.
 */
class EnsureSuchakLegacyOtpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::enabled()) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code' => 'legacy_otp_disabled',
            'message' => 'Code sign-in is switched off. Sign in with phone verification instead.',
        ], 410);
    }

    /**
     * Unset means: on everywhere except production.
     */
    public static function enabled(): bool
    {
        $configured = config('firebase_auth.legacy_suchak_otp');

        if ($configured === null || $configured === '') {
            return ! app()->isProduction();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOL);
    }
}
