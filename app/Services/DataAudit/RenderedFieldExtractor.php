<?php

namespace App\Services\DataAudit;

use Symfony\Component\Yaml\Yaml;

class RenderedFieldExtractor
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /**
     * @param  array<string, mixed>  $dbValues
     * @return array<string, array{raw_rendered: string|null, normalized: string|null}>
     */
    public function extract(string $html, array $dbValues): array
    {
        $text = $this->normalizeText(strip_tags($html));
        $lines = $this->extractLines($html);
        $manifest = $this->loadManifest();

        $out = [];
        foreach ($dbValues as $field => $dbValue) {
            $raw = null;
            $normalized = null;

            if (is_scalar($dbValue) && $dbValue !== null) {
                $dbString = trim((string) $dbValue);
                if ($dbString !== '' && mb_stripos($text, $dbString) !== false) {
                    $raw = $dbString;
                    $normalized = $dbString;
                }
            }

            if ($raw === null) {
                $raw = $this->extractFromManifest($field, $html, $lines, $manifest);
                if ($raw !== null) {
                    $normalized = $this->normalizeText($raw);
                }
            }

            if ($field === 'height_cm' && $raw === null) {
                if (preg_match('/\b\d+\s*ft\s*\d+\s*in\b/i', $text, $m) === 1) {
                    $raw = $m[0];
                    $normalized = strtolower(trim($m[0]));
                }
            }

            $out[$field] = [
                'raw_rendered' => $raw,
                'normalized' => $normalized,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $lines
     */
    private function extractFromManifest(string $field, string $html, array $lines, array $manifest): ?string
    {
        $cfg = $manifest[$field] ?? null;
        if (! is_array($cfg)) {
            return null;
        }

        $inputNames = isset($cfg['input_names']) && is_array($cfg['input_names']) ? $cfg['input_names'] : [];
        foreach ($inputNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $value = $this->extractInputValue($html, $name);
            if ($value !== null) {
                return $value;
            }
        }

        $patterns = isset($cfg['value_patterns']) && is_array($cfg['value_patterns']) ? $cfg['value_patterns'] : [];
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $m = [];
            if (@preg_match('/'.$pattern.'/iu', $html, $m) === 1) {
                $value = isset($m[1]) ? trim((string) $m[1]) : trim((string) $m[0]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $selectors = isset($cfg['selectors']) && is_array($cfg['selectors']) ? $cfg['selectors'] : [];
        foreach ($selectors as $selector) {
            $label = trim((string) $selector);
            if ($label === '') {
                continue;
            }
            $value = $this->extractByLabelFromLines($lines, $label);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function extractInputValue(string $html, string $name): ?string
    {
        $nameQuoted = preg_quote($name, '/');
        $m = [];

        if (preg_match('/<input\b[^>]*\bname\s*=\s*["\']'.$nameQuoted.'["\'][^>]*>/iu', $html, $m) === 1) {
            $tag = $m[0];
            if (preg_match('/\bvalue\s*=\s*["\']([^"\']*)["\']/iu', $tag, $vm) === 1) {
                $value = trim(html_entity_decode((string) $vm[1]));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        if (preg_match('/<select\b[^>]*\bname\s*=\s*["\']'.$nameQuoted.'["\'][^>]*>(.*?)<\/select>/isu', $html, $m) === 1) {
            $selectBody = $m[1];
            if (preg_match('/<option\b[^>]*selected[^>]*>(.*?)<\/option>/isu', $selectBody, $om) === 1) {
                $value = $this->normalizeText(strip_tags((string) $om[1]));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function extractByLabelFromLines(array $lines, string $label): ?string
    {
        $needle = mb_strtolower($this->normalizeText($label));
        foreach ($lines as $idx => $line) {
            $lineNorm = mb_strtolower($this->normalizeText($line));
            if ($lineNorm === '' || mb_stripos($lineNorm, $needle) === false) {
                continue;
            }

            $sameLine = trim(preg_replace('/'.preg_quote($label, '/').'\s*[:\-]?\s*/iu', '', $line) ?? '');
            if ($sameLine !== '' && mb_strtolower($sameLine) !== $needle) {
                return $sameLine;
            }

            for ($i = $idx + 1; $i <= min($idx + 4, count($lines) - 1); $i++) {
                $candidate = $this->normalizeText($lines[$i]);
                if ($candidate === '') {
                    continue;
                }
                if (mb_stripos(mb_strtolower($candidate), $needle) !== false) {
                    continue;
                }
                if (in_array(mb_strtolower($candidate), ['select', 'select religion first, then type to search'], true)) {
                    continue;
                }

                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractLines(string $html): array
    {
        $prepared = preg_replace('/<\/(p|div|li|td|th|dt|dd|label|h[1-6]|option|button|span)>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($prepared));
        $rows = preg_split('/\R+/u', $text) ?: [];
        $lines = [];
        foreach ($rows as $row) {
            $line = $this->normalizeText((string) $row);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = base_path('python-data-engine/config/render_extractors.yml');
        if (! is_file($path)) {
            $this->manifest = [];

            return $this->manifest;
        }

        try {
            $parsed = Yaml::parseFile($path);
            $this->manifest = is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            $this->manifest = [];
        }

        return $this->manifest;
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}

