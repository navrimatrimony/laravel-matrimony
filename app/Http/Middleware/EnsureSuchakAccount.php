<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

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
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        if (Route::has('dashboard')) {
            return redirect()
                ->route('dashboard')
                ->with('info', 'Suchak account is required to access this section.');
        }

        abort(403, 'Suchak account is required to access this section.');
    }
}
