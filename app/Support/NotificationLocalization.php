<?php

namespace App\Support;

use App\Models\Translation;
use App\Models\User;

/**
 * Bilingual notification copy: English users see English only; Marathi users see Marathi only.
 */
final class NotificationLocalization
{
    public const LOCALE_EN = 'en';

    public const LOCALE_MR = 'mr';

    /**
     * @param  array<string, scalar|\Stringable|null>  $replace
     * @return array{message: string, message_mr: string}
     */
    public static function pair(string $key, array $replace = []): array
    {
        return [
            'message' => self::translate($key, $replace, self::LOCALE_EN),
            'message_mr' => self::translate($key, $replace, self::LOCALE_MR),
        ];
    }

    /**
     * @param  array<string, scalar|\Stringable|null>  $replace
     */
    public static function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = self::normalize($locale ?? app()->getLocale());
        $previous = app()->getLocale();
        app()->setLocale($locale);
        Translation::loadIntoTranslator($locale);

        try {
            return (string) __($key, $replace);
        } finally {
            app()->setLocale($previous);
            Translation::loadIntoTranslator($previous);
        }
    }

    /**
     * Message for UI or email for the given user/session locale. Never mixes languages.
     *
     * @param  array<string, mixed>  $data
     */
    public static function displayMessage(array $data, ?string $locale = null): string
    {
        $locale = self::normalize($locale ?? app()->getLocale());

        if (self::isMarathi($locale)) {
            $mr = trim((string) ($data['message_mr'] ?? ''));
            if ($mr !== '') {
                return $mr;
            }
        }

        $en = trim((string) ($data['message'] ?? ''));

        return $en !== '' ? $en : trim((string) ($data['message_mr'] ?? 'Notification'));
    }

    public static function preferredLocaleForUser(?User $user): string
    {
        if ($user !== null) {
            $stored = trim((string) ($user->preferred_locale ?? ''));
            if ($stored !== '' && in_array($stored, [self::LOCALE_EN, self::LOCALE_MR], true)) {
                return $stored;
            }
        }

        return self::normalize(app()->getLocale());
    }

    /**
     * Merge bilingual strings into a notification payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function enrichPayload(array $payload): array
    {
        if (isset($payload['message_key']) && is_string($payload['message_key'])) {
            $params = is_array($payload['message_params'] ?? null) ? $payload['message_params'] : [];
            $pair = self::pair((string) $payload['message_key'], $params);
            $payload['message'] = $pair['message'];
            $payload['message_mr'] = $pair['message_mr'];
            unset($payload['message_key'], $payload['message_params']);
        } elseif (! isset($payload['message_mr']) || trim((string) $payload['message_mr']) === '') {
            $mr = NotificationMarathiPayload::message($payload);
            if ($mr !== null && $mr !== '') {
                $payload['message_mr'] = $mr;
            }
        }

        return $payload;
    }

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return str_starts_with($locale, self::LOCALE_MR) ? self::LOCALE_MR : self::LOCALE_EN;
    }

    public static function isMarathi(?string $locale): bool
    {
        return self::normalize($locale) === self::LOCALE_MR;
    }
}
