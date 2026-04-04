<?php

namespace App\Services\Parsing;

/**
 * Config-driven synonym → canonical label mapping for intake text fields.
 * Does not replace master-data *_id columns (left to IntakeControlledFieldNormalizer).
 */
class IntakeDictionaryMapper
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function mapSnapshot(array $snapshot): array
    {
        $out = $snapshot;
        $trace = is_array($out['_intake_dictionary'] ?? null) ? $out['_intake_dictionary'] : [];

        $coreMap = config('intake_dictionary.core_field_map', []);
        if (isset($out['core']) && is_array($out['core'])) {
            foreach ($out['core'] as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                if (! is_string($value) && ! is_numeric($value)) {
                    continue;
                }
                if (is_string($value) && trim($value) === '') {
                    continue;
                }
                if (str_ends_with($key, '_id') && is_numeric($value) && (int) $value !== 0) {
                    continue;
                }
                $dictKey = $coreMap[$key] ?? null;
                if ($dictKey === null) {
                    continue;
                }
                $raw = is_string($value) ? $value : (string) $value;
                $mapped = $this->mapTextField($dictKey, $raw);
                if ($mapped === null) {
                    continue;
                }
                $out['core'][$key] = $mapped['mapped'];
                $trace['core'][$key] = $mapped;
            }
        }

        $extMap = config('intake_dictionary.extended_field_map', []);
        if (isset($out['extended']) && is_array($out['extended'])) {
            foreach ($out['extended'] as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                if (! is_string($value)) {
                    continue;
                }
                if (trim($value) === '') {
                    continue;
                }
                $dictKey = $extMap[$key] ?? null;
                if ($dictKey === null) {
                    continue;
                }
                $mapped = $this->mapTextField($dictKey, $value);
                if ($mapped === null) {
                    continue;
                }
                $out['extended'][$key] = $mapped['mapped'];
                $trace['extended'][$key] = $mapped;
            }
        }

        $this->mapPlaceTextArrays($out, $trace);

        if ($trace !== []) {
            $out['_intake_dictionary'] = $trace;
        } elseif (isset($out['_intake_dictionary'])) {
            unset($out['_intake_dictionary']);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $trace
     */
    private function mapPlaceTextArrays(array &$out, array &$trace): void
    {
        $syn = config('intake_dictionary.location_text.synonyms', []);
        if ($syn === []) {
            return;
        }
        foreach (['birth_place', 'native_place'] as $placeKey) {
            if (! isset($out[$placeKey]) || ! is_array($out[$placeKey])) {
                continue;
            }
            $place = &$out[$placeKey];
            foreach (['city_text', 'taluka_text', 'district_text', 'state_text', 'label'] as $tk) {
                if (! isset($place[$tk]) || ! is_string($place[$tk])) {
                    continue;
                }
                $raw = trim($place[$tk]);
                if ($raw === '') {
                    continue;
                }
                $slug = $this->slugForLookup($raw);
                $canonical = $syn[$slug] ?? null;
                if ($canonical === null) {
                    foreach ($syn as $from => $to) {
                        if ($this->slugForLookup((string) $from) === $slug) {
                            $canonical = $to;

                            break;
                        }
                    }
                }
                if ($canonical !== null && $canonical !== $place[$tk]) {
                    $trace[$placeKey][$tk] = [
                        'raw' => $place[$tk],
                        'normalized' => $slug,
                        'mapped' => $canonical,
                    ];
                    $place[$tk] = $canonical;
                }
            }
            unset($place);
        }
    }

    /**
     * @return array{raw: string, normalized: string, mapped: string}|null
     */
    private function mapTextField(string $dictionarySection, string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $slug = $this->slugForLookup($trimmed);
        $tokens = config("intake_dictionary.{$dictionarySection}.tokens", []);
        $synonyms = config("intake_dictionary.{$dictionarySection}.synonyms", []);

        $tokenKey = $synonyms[$slug] ?? null;
        if ($tokenKey === null) {
            foreach ($synonyms as $from => $to) {
                if ($this->slugForLookup((string) $from) === $slug) {
                    $tokenKey = $to;

                    break;
                }
            }
        }

        if ($tokenKey === null && isset($tokens[$slug])) {
            $tokenKey = $slug;
        }

        $normalizedToken = is_string($tokenKey) ? $this->slugForLookup($tokenKey) : $slug;
        $label = null;
        if (is_string($tokenKey) && isset($tokens[$this->slugForLookup($tokenKey)])) {
            $label = $tokens[$this->slugForLookup($tokenKey)];
        } elseif (isset($tokens[$normalizedToken])) {
            $label = $tokens[$normalizedToken];
        }

        if ($label === null) {
            return null;
        }

        return [
            'raw' => $trimmed,
            'normalized' => $normalizedToken,
            'mapped' => $label,
        ];
    }

    private function slugForLookup(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[.\s,_\-]+/u', ' ', $s) ?? $s;
        $s = trim((string) $s);

        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }
}
