<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

trait SetsApiLocaleFromRequest
{
    protected function applyLocaleFromApiRequest(Request $request): void
    {
        $locale = $request->query('locale');
        if (is_string($locale) && $locale !== '') {
            $primary = strtolower(explode('-', trim($locale), 2)[0]);
            if ($primary === 'mr') {
                app()->setLocale('mr');

                return;
            }
            if ($primary === 'en') {
                app()->setLocale('en');

                return;
            }
        }

        if ($request->getPreferredLanguage(['mr', 'en', 'hi']) === 'mr') {
            app()->setLocale('mr');
        }
    }
}
