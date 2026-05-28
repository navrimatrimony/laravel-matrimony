<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| SetLocaleFromQuery Middleware
|--------------------------------------------------------------------------
|
| Reads ?locale=en|mr from query string; persists in session.
| Only en and mr are allowed. Default remains English.
|
*/
class SetLocaleFromQuery
{
    private const ALLOWED = ['en', 'mr'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        $queryLocale = $request->query('locale');
        if ($queryLocale !== null && in_array($queryLocale, self::ALLOWED, true)) {
            $locale = $queryLocale;
            $request->session()->put('locale', $locale);
        }

        if ($locale === null) {
            $locale = $request->session()->get('locale');
        }

        if ($locale === null || !in_array($locale, self::ALLOWED, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        \App\Models\Translation::loadIntoTranslator($locale);

        $user = $request->user();
        if ($user !== null && in_array($locale, self::ALLOWED, true)) {
            $user->forceFill(['preferred_locale' => $locale])->saveQuietly();
        }

        return $next($request);
    }
}
