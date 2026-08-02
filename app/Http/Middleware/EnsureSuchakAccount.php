<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate on every Suchak route, and the FIRST sentence a caller without a
 * Suchak account ever hears.
 *
 * It said "Suchak account is required to access this section." in English, in
 * three places, to a product whose Suchaks read Marathi — while eleven
 * controllers behind this gate said the same thing in Marathi. Both now read
 * `suchak.api.errors.suchak_account_required`, so the refusal is one sentence
 * in one place and arrives in whatever language the caller asked for.
 */
class EnsureSuchakAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->suchakAccount()->exists()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => __('suchak.api.errors.suchak_account_required'),
            ], 403);
        }

        if (Route::has('dashboard')) {
            return redirect()
                ->route('dashboard')
                ->with('info', __('suchak.api.errors.suchak_account_required'));
        }

        abort(403, __('suchak.api.errors.suchak_account_required'));
    }
}
