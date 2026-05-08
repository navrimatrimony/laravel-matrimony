<?php

namespace App\Services\Governance;

class RecursiveRenderedFieldExtractor
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function flatten(array $payload): array
    {
        $out = [];
        $this->walk($payload, '', $out);
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function extractAgainstHtml(array $payload, string $html): array
    {
        $flat = $this->flatten($payload);
        $haystack = mb_strtolower($this->normalizeText(strip_tags($html)));
        $out = [];
        foreach ($flat as $path => $value) {
            if (! is_scalar($value) || $value === null) {
                $out[$path] = null;
                continue;
            }
            $needle = mb_strtolower($this->normalizeText((string) $value));
            if ($needle === '') {
                $out[$path] = null;
                continue;
            }
            $out[$path] = mb_stripos($haystack, $needle) !== false ? (string) $value : null;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $out
     * @param  mixed  $value
     */
    private function walk(mixed $value, string $prefix, array &$out): void
    {
        if (is_array($value)) {
            if ($value === []) {
                $out[$prefix] = [];
                return;
            }
            foreach ($value as $k => $v) {
                $next = $prefix === '' ? (string) $k : $prefix.'.'.$k;
                $this->walk($v, $next, $out);
            }
            return;
        }

        if ($prefix !== '') {
            $out[$prefix] = $value;
        }
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}

