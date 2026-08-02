<?php

namespace App\Http\Middleware;

use App\Models\Translation;
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

    /**
     * Resolving the locale is only half of speaking the caller's language.
     *
     * Translations in this product are ADMIN-EDITABLE: `translations` rows
     * override the `lang/` files, and {@see Translation::loadIntoTranslator()}
     * is what puts them in front of the file values for a given locale. The web
     * group has called it from {@see SetLocaleFromQuery} for a long time; this
     * middleware set the locale and stopped there, so every `__()` on the api
     * group silently read the FILE value and an admin's correction reached the
     * website but never reached either Flutter app.
     *
     * Called here, on the same line of the request as `setLocale()`, because
     * the two facts are one decision: the language a response is written in,
     * and the wording that language currently uses. Splitting them is what
     * produced the gap.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        Translation::loadIntoTranslator($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        // 1. An explicit override on the request itself — `?locale=` or a
        //    `locale` field in the body. Wins over everything.
        if ($language = $this->supported($request->input('locale'))) {
            return $language;
        }

        // 2. The app's live choice. Both Flutter apps send Accept-Language from
        //    the language the member picked in-app, so on the API that header is
        //    the authoritative choice and must beat a possibly-stale saved
        //    preference — otherwise a member who switches to Marathi keeps being
        //    served English from a preferred_locale written at registration.
        foreach ($request->getLanguages() as $tag) {
            if ($language = $this->supported($tag)) {
                return $language;
            }
        }

        // 3. The signed-in user's saved preference — only when the client sent
        //    no usable Accept-Language at all (older builds, or a bare request).
        if ($language = $this->supported($this->savedPreference($request))) {
            return $language;
        }

        // 4. Marathi-first product default.
        return 'mr';
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

        $primary = strtolower(preg_split('/[-_]/', $value, 2)[0]);

        return in_array($primary, self::SUPPORTED, true) ? $primary : null;
    }
}
