<?php

namespace App\Http\Middleware;

use App\Services\MemberPresencePresentationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserLastSeen
{
    /**
     * Refresh authenticated user's last_seen_at periodically (cadence aligns with admin "online" threshold, capped).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null) {
            $threshold = app(MemberPresencePresentationService::class)->onlineThresholdMinutes();
            $cadenceMinutes = max(1, min(5, $threshold));
            $last = $user->last_seen_at;
            if ($last === null || $last->lt(now()->subMinutes($cadenceMinutes))) {
                $user->last_seen_at = now();
                $user->saveQuietly();
            }
        }

        return $next($request);
    }
}
