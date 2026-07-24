<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides the request language once, for the whole `api` group.
 *
 * The web group has had {@see SetLocaleFromQuery} for a long time; the api
 * group had nothing, so six controllers each called `app()->setLocale()`
 * themselves and each invented its own precedence. The chain below is the
 * union of all of them, so no endpoint loses behaviour when those calls go:
 *
 *   explicit `locale` on the request  →  the signed-in user's saved preference
 *   →  the client's `Accept-Language`  →  English
 *
 * A locale the app has no translations for is not honoured — falling through
 * to English is better than half-translating a screen into a third language.
 */
class SetApiLocale
{
    /**
     * Locales the application ships copy for, **best first**.
     *
     * Order matters: `getPreferredLanguage()` returns the head of this list
     * when the client sends no `Accept-Language` or none that matches. Marathi
     * leads because this is a Marathi-first product — both Flutter apps open in
     * Marathi, and the trait this middleware replaces defaulted to Marathi for
     * the location endpoints. Listing English first would silently flip those
     * responses to English for every client that sends no header.
     */
    private const SUPPORTED = ['mr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        // Covers both `?locale=` and a `locale` field in the body.
        if ($language = $this->supported($request->input('locale'))) {
            return $language;
        }

        if ($language = $this->supported($this->savedPreference($request))) {
            return $language;
        }

        return \App\Support\LocalizedText::isMarathi($request->getPreferredLanguage(self::SUPPORTED)) ? 'mr' : 'en';
    }

    /**
     * The signed-in user's saved language, read through the token guard.
     *
     * Group middleware runs before the route's `auth:sanctum`, so at this point
     * the default guard is still `web` and `$request->user()` is null for a
     * Bearer-token request — which is every call the two Flutter apps make.
     * Naming the guard resolves the token holder here, which is the whole point
     * of consulting the preference at all.
     */
    private function savedPreference(Request $request): ?string
    {
        return $request->user('sanctum')?->preferred_locale
            ?? $request->user()?->preferred_locale;
    }

    /**
     * Narrow a tag to a language we ship, or null.
     *
     * `mr-IN` means Marathi — a client sending a regional tag still names the
     * language, and a stored preference may carry one.
     */
    private function supported(mixed $tag): ?string
    {
        $value = trim((string) ($tag ?? ''));
        if ($value === '') {
            return null;
        }

        $primary = strtolower(explode('-', $value, 2)[0]);

        return in_array($primary, self::SUPPORTED, true) ? $primary : null;
    }
}
