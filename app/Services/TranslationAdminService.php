<?php

namespace App\Services;

use App\Models\Translation;
use Illuminate\Support\Str;

/**
 * Admin panel: list keys from lang files + DB overrides; key is read-only, only values editable.
 */
class TranslationAdminService
{
    private const LOCALES = ['en', 'mr'];

    private const LANG_NAMESPACES = ['components', 'wizard', 'common', 'nav', 'profile', 'actions', 'match', 'contact', 'admin', 'intake', 'validation', 'messages', 'notifications', 'dashboard', 'auth', 'pagination', 'passwords', 'otp', 'photo', 'interests', 'who_viewed', 'profile_actions', 'interest'];

    /**
     * Flatten a lang array to dot keys.
     *
     * @return array<string, string>
     */
    public function flattenLangArray(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? $k : $prefix . '.' . $k;
            // Arrays (including "list of strings" cases) should always be flattened further instead of cast to string.
            if (is_array($v)) {
                $out = array_merge($out, $this->flattenLangArray($v, $key));
            } else {
                $out[$key] = is_string($v) ? $v : (string) $v;
            }
        }
        return $out;
    }

    private function isListOfStrings(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        $i = 0;
        foreach ($arr as $k => $v) {
            if ($k !== $i || !is_string($v)) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /**
     * Load all keys from lang files for a locale (from namespace files only).
     *
     * @return array<string, string>
     */
    public function getKeysFromFiles(string $locale): array
    {
        $all = [];
        $langPath = lang_path($locale);
        foreach (self::LANG_NAMESPACES as $ns) {
            $file = $langPath . DIRECTORY_SEPARATOR . $ns . '.php';
            if (!is_file($file)) {
                continue;
            }
            $arr = include $file;
            if (!is_array($arr)) {
                continue;
            }
            $flat = $this->flattenLangArray($arr, $ns);
            $all = array_merge($all, $flat);
        }
        return $all;
    }

    /**
     * All unique keys from both en and mr files.
     *
     * @return array<int, string>
     */
    public function getAllKeys(): array
    {
        $en = $this->getKeysFromFiles('en');
        $mr = $this->getKeysFromFiles('mr');
        $keys = array_unique(array_merge(array_keys($en), array_keys($mr)));
        sort($keys);
        return array_values($keys);
    }

    /**
     * For admin list: each key with value_en, value_mr (DB override or file).
     *
     * @return array<int, array{key: string, value_en: string, value_mr: string, in_db: bool}>
     */
    public function getListForAdmin(?string $namespaceFilter = null, ?string $search = null): array
    {
        $keys = $this->getAllKeys();
        $dbByKey = Translation::query()
            ->get()
            ->groupBy('key')
            ->map(fn ($rows) => $rows->keyBy('locale'));

        $enFile = $this->getKeysFromFiles('en');
        $mrFile = $this->getKeysFromFiles('mr');

        $list = [];
        foreach ($keys as $key) {
            if ($namespaceFilter !== null && $namespaceFilter !== '' && !Str::startsWith($key, $namespaceFilter . '.')) {
                continue;
            }
            if ($search !== null && $search !== '' && !Str::contains(strtolower($key), strtolower($search))) {
                continue;
            }

            $valueEn = $dbByKey->get($key)?->get('en')?->value ?? $enFile[$key] ?? '';
            $valueMr = $dbByKey->get($key)?->get('mr')?->value ?? $mrFile[$key] ?? '';
            $inDb = $dbByKey->has($key);

            $list[] = [
                'key' => $key,
                'value_en' => $valueEn,
                'value_mr' => $valueMr,
                'in_db' => $inDb,
            ];
        }
        return $list;
    }

    /**
     * Get namespace options for filter (top-level segment).
     *
     * @return array<string, string>
     */
    public function getNamespaceOptions(): array
    {
        $keys = $this->getAllKeys();
        $namespaces = [];
        foreach ($keys as $key) {
            $ns = explode('.', $key)[0] ?? '';
            if ($ns !== '') {
                $namespaces[$ns] = $ns;
            }
        }
        ksort($namespaces);
        return $namespaces;
    }

    /**
     * Update or create translation rows for one key (en + mr). Key itself is never changed.
     */
    public function saveKey(string $key, string $valueEn, string $valueMr): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }
        foreach (self::LOCALES as $locale) {
            $value = $locale === 'en' ? $valueEn : $valueMr;
            Translation::updateOrCreate(
                ['locale' => $locale, 'key' => $key],
                ['value' => $value]
            );
        }
    }

    /**
     * Add new alias (new key). Key must be valid dot path; values for en and mr.
     */
    public function addAlias(string $newKey, string $valueEn, string $valueMr): void
    {
        $newKey = trim($newKey);
        if ($newKey === '') {
            throw new \InvalidArgumentException('Key cannot be empty.');
        }
        if (!preg_match('/^[a-z0-9_.]+$/i', $newKey)) {
            throw new \InvalidArgumentException('Key must contain only letters, numbers, dots and underscores.');
        }
        $this->saveKey($newKey, $valueEn, $valueMr);
    }
}
