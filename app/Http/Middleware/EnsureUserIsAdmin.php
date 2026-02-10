<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| EnsureUserIsAdmin Middleware
|--------------------------------------------------------------------------
|
| 👉 Checks if user is authenticated and has admin privileges
| 👉 Denies access if user is not authenticated or not an admin
| 👉 Returns appropriate response for web vs API requests
|
*/
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!$request->user()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required',
                ], 401);
            }

            abort(403);
        }

        // Check if user has admin privileges (Day-7: supports role-based admin)
        if (!$request->user()->isAnyAdmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required',
                ], 403);
            }

            abort(403);
        }

        return $next($request);
    }
}