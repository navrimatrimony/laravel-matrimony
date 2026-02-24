<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| EnsureMatrimonyProfileExists Middleware
|--------------------------------------------------------------------------
|
| 👉 Logged-in user कडे matrimony profile आहे का ते तपासतो
| 👉 नसेल तर user ला जबरदस्तीने profile create page वर पाठवतो
|
| SSOT v3.1 §13:
| Registration → Matrimony Profile mandatory
|
*/

class EnsureMatrimonyProfileExists
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // User logged-in नसेल तर auth middleware handle करेल
        $user = $request->user();

        // User login आहे पण profile नाही
        if ($user && !$user->matrimonyProfile) {

            // Infinite loop टाळण्यासाठी:
            // जर user आधीच profile create page वर असेल तर allow करा
            if ($request->routeIs('matrimony.profile.wizard*')) {
                return $next($request);
            }

            // बाकी सर्व routes साठी force redirect
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info']);
        }

        return $next($request);
    }
}
