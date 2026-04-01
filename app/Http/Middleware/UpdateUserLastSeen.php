<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserLastSeen
{
    /**
     * Refresh authenticated user's last_seen_at at most once every 5 minutes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null) {
            $last = $user->last_seen_at;
            if ($last === null || $last->lt(now()->subMinutes(5))) {
                $user->last_seen_at = now();
                $user->saveQuietly();
            }
        }

        return $next($request);
    }
}
