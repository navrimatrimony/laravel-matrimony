<?php

namespace App\Services\Location;

/**
 * Biodata-style place strings: "वरकुटे-मलवडी, ता. माण, जि. सातारा" or "तासगाव ता. - तासगाव, जि. - सांगली".
 */
final class LocationCompoundAddressParser
{
    /**
     * @return array{village: string, taluka: string, district: string}
     */
    public function parseComponents(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['village' => '', 'taluka' => '', 'district' => ''];
        }

        $s = preg_replace('/\s*[-–—]\s*/u', ' ', $raw) ?? $raw;
        $s = trim((string) (preg_replace('/\s+/u', ' ', $s) ?? $s));

        $village = '';
        $taluka = '';
        $district = '';

        foreach ($this->splitSegments($s) as $part) {
            [$kind, $name] = $this->classifyAdminSegment($part);
            $name = $this->cleanSegment($name);
            if ($name === '') {
                continue;
            }
            if ($kind === 'district') {
                $district = $name;
            } elseif ($kind === 'taluka') {
                $taluka = $name;
            } elseif ($kind === 'unknown') {
                [$partVillage, $partTaluka] = $this->splitVillageAndTalukaInSegment($name);
                if ($village === '' && $partVillage !== '') {
                    $village = $partVillage;
                }
                if ($taluka === '' && $partTaluka !== '') {
                    $taluka = $partTaluka;
                }
            }
        }

        if ($village === '' && $s !== '') {
            [$village, $embeddedTaluka] = $this->splitVillageAndTalukaInSegment($s);
            if ($taluka === '' && $embeddedTaluka !== '') {
                $taluka = $embeddedTaluka;
            }
        }

        return [
            'village' => $village,
            'taluka' => $taluka,
            'district' => $district,
        ];
    }

    /**
     * @return array{0: string, 1: string} village, taluka
     */
    private function splitVillageAndTalukaInSegment(string $segment): array
    {
        $segment = trim($segment);
        $parts = preg_split('/\s+(?:ता\.|तालुका|taluka|ta\.)\s*/iu', $segment, 2) ?: [];
        if (count($parts) >= 2) {
            return [
                $this->normalizeVillageName($this->cleanSegment((string) $parts[0])),
                $this->cleanSegment((string) $parts[1]),
            ];
        }

        return [$this->normalizeVillageName($this->cleanSegment($segment)), ''];
    }

    /**
     * @return list<string>
     */
    public function aliasLookupKeys(string $raw): array
    {
        $keys = [];
        $add = function (string $k) use (&$keys): void {
            $k = $this->normalizeAliasKey($k);
            if ($k !== '' && ! in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        };

        $add($raw);
        $components = $this->parseComponents($raw);
        if ($components['village'] !== '') {
            $add($components['village']);
        }
        if ($components['village'] !== '' && $components['taluka'] !== '') {
            $add($components['village'].' '.$components['taluka']);
        }
        if ($components['village'] !== '' && $components['district'] !== '') {
            $add($components['village'].' '.$components['district']);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function searchQueries(string $raw): array
    {
        $queries = [];
        $add = function (string $q) use (&$queries): void {
            $q = trim($q);
            if ($q !== '' && ! in_array($q, $queries, true)) {
                $queries[] = $q;
            }
        };

        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $components = $this->parseComponents($raw);

        if ($components['village'] !== '' && $components['taluka'] !== '') {
            $add($components['village'].' '.$components['taluka']);
        }
        if ($components['village'] !== '') {
            $add($components['village']);
        }
        if ($components['taluka'] !== '' && $components['taluka'] !== $components['village']) {
            $add($components['taluka']);
        }
        if ($components['village'] !== '' && $components['district'] !== '') {
            $add($components['village'].' '.$components['district']);
        }

        $firstWord = preg_split('/\s+/u', $components['village'], 2)[0] ?? '';
        if (mb_strlen($firstWord) >= 3) {
            $add($firstWord);
        }

        $add($this->normalizeVillageName($this->cleanSegment($raw)));
        $add($raw);

        return $queries;
    }

    public function looksCompound(string $raw): bool
    {
        $raw = trim($raw);

        return $raw !== '' && (
            str_contains($raw, ',')
            || preg_match('/\b(ता\.?|जि\.?|तालुका|जिल्हा|taluka|ta\.|dist\.?|district)\b/iu', $raw) === 1
        );
    }

    /**
     * @return list<string>
     */
    private function splitSegments(string $raw): array
    {
        $parts = preg_split('/[,;|]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $seg = trim((string) $part);
            if ($seg !== '') {
                $out[] = $seg;
            }
        }

        return $out;
    }

    private function cleanSegment(string $segment): string
    {
        $s = trim($segment);
        $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        $s = preg_replace('/^(ता\.|जि\.|जिल्हा|तालुका|taluka|ta\.|dist\.|district)\s*/iu', '', $s) ?? $s;
        $s = preg_replace('/\s*(ता\.|जि\.|जिल्हा|तालुका|taluka|ta\.|dist\.|district)\s*$/iu', '', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B,;.-");

        return trim((string) (preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    private function normalizeVillageName(string $name): string
    {
        $name = preg_replace('/[-–—]+/u', ' ', $name) ?? $name;

        return trim((string) (preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    /**
     * @return array{0: 'taluka'|'district'|'unknown', 1: string}
     */
    private function classifyAdminSegment(string $segment): array
    {
        $seg = trim($segment);
        if (preg_match('/^(ता\.|तालुका|taluka|ta\.)\s*(.+)$/iu', $seg, $m)) {
            return ['taluka', trim((string) $m[2])];
        }
        if (preg_match('/^(जि\.|जिल्हा|dist\.|district)\s*(.+)$/iu', $seg, $m)) {
            return ['district', trim((string) $m[2])];
        }

        return ['unknown', $seg];
    }

    private function normalizeAliasKey(string $s): string
    {
        $s = str_replace('.', ' ', $s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $s) ?? $s;

        return trim((string) (preg_replace('/\s+/u', ' ', $s) ?? $s));
    }
}
