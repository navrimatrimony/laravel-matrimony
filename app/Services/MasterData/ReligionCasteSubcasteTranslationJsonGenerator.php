<?php

namespace App\Services\MasterData;

use App\Support\MasterData\ReligionBilingualCatalog;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;
use App\Support\MasterData\RomanToDevanagariApprox;

/**
 * Builds translation JSON entries from the canonical TSV (same shape as master import).
 */
class ReligionCasteSubcasteTranslationJsonGenerator
{
    public function __construct(
        private ReligionCasteSubcasteSlugger $slugger
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function buildEntriesFromTsv(string $absolutePath): array
    {
        $parsed = $this->parseTsv($absolutePath);
        $entries = [];

        $bilingual = ReligionBilingualCatalog::load();
        if ($bilingual !== []) {
            foreach ($bilingual as $key => $meta) {
                $key = trim((string) $key);
                $labelEn = trim((string) ($meta['label_en'] ?? ''));
                if ($key === '' || $labelEn === '') {
                    continue;
                }
                $mrRaw = trim((string) ($meta['label_mr'] ?? ''));
                $labelMr = $mrRaw !== '' ? $mrRaw : $this->marathiForEnglish($labelEn);
                $entries[] = $this->makeReligionEntryWithKey($key, $labelEn, $labelMr);
            }
        } else {
            foreach ($parsed['religions'] as $relLabel) {
                $key = $this->slugger->makeKey($relLabel);
                $entries[] = $this->makeEntry('religion', $key, [], $relLabel);
            }
        }

        foreach ($parsed['castes'] as $row) {
            [$relLabel, $casteLabel] = $row;
            $relKey = $this->slugger->makeKey($relLabel);
            $casteKey = $this->slugger->makeKey($casteLabel);
            $entries[] = $this->makeEntry('caste', $casteKey, ['religion_key' => $relKey], $casteLabel);
        }

        foreach ($parsed['subcastes'] as $row) {
            [$relLabel, $casteLabel, $subLabel] = $row;
            $relKey = $this->slugger->makeKey($relLabel);
            $casteKey = $this->slugger->makeKey($casteLabel);
            $subKey = $this->slugger->makeKey($subLabel);
            $entries[] = $this->makeEntry('sub_caste', $subKey, [
                'religion_key' => $relKey,
                'caste_key' => $casteKey,
            ], $subLabel);
        }

        return $entries;
    }

    /**
     * @return array{religions: list<string>, castes: list<array{0: string, 1: string}>, subcastes: list<array{0: string, 1: string, 2: string}>}
     */
    private function parseTsv(string $absolutePath): array
    {
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new \RuntimeException('Cannot read TSV: '.$absolutePath);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\R/u', $content) ?: [];

        $headerSeen = false;
        $seenRel = [];
        $seenCaste = [];
        $seenSub = [];
        $religions = [];
        $castes = [];
        $subcastes = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line, "\t", '"', '\\');
            if (! $headerSeen) {
                $headerSeen = true;

                continue;
            }

            $rel = isset($parts[0]) ? $this->slugger->normalizeLabel((string) $parts[0]) : '';
            $caste = isset($parts[1]) ? $this->slugger->normalizeLabel((string) $parts[1]) : '';
            $sub = isset($parts[2]) ? $this->slugger->normalizeLabel((string) $parts[2]) : '';

            if ($rel === '' && $caste === '' && $sub === '') {
                continue;
            }
            if ($caste === '' && $sub !== '') {
                continue;
            }

            if ($rel !== '') {
                $rk = $rel;
                if (! isset($seenRel[$rk])) {
                    $seenRel[$rk] = true;
                    $religions[] = $rel;
                }
            }
            if ($rel !== '' && $caste !== '') {
                $ck = $rel."\n".$caste;
                if (! isset($seenCaste[$ck])) {
                    $seenCaste[$ck] = true;
                    $castes[] = [$rel, $caste];
                }
            }
            if ($rel !== '' && $caste !== '' && $sub !== '') {
                $sk = $rel."\n".$caste."\n".$sub;
                if (! isset($seenSub[$sk])) {
                    $seenSub[$sk] = true;
                    $subcastes[] = [$rel, $caste, $sub];
                }
            }
        }

        return [
            'religions' => $religions,
            'castes' => $castes,
            'subcastes' => $subcastes,
        ];
    }

    /**
     * @param  array<string, string>  $scope
     * @return array<string, mixed>
     */
    /**
     * Religion row with an explicit stable key (matches {@see ReligionBilingualCatalog}).
     *
     * @return array<string, mixed>
     */
    private function makeReligionEntryWithKey(string $key, string $labelEn, string $labelMr): array
    {
        $aliasesEn = $this->buildAliasesEn($labelEn);
        $aliasesMr = array_values(array_unique(array_filter([$labelMr])));
        $ocr = $this->buildOcrVariants($labelEn, $aliasesEn);

        return [
            'entity_type' => 'religion',
            'key' => $key,
            'scope' => [],
            'label_en' => $labelEn,
            'label_mr' => $labelMr,
            'aliases_en' => $aliasesEn,
            'aliases_mr' => $aliasesMr,
            'ocr_variants' => $ocr,
        ];
    }

    /**
     * @param  array<string, string>  $scope
     * @return array<string, mixed>
     */
    private function makeEntry(string $entityType, string $key, array $scope, string $labelEn): array
    {
        $labelMr = $this->marathiForEnglish($labelEn);
        $aliasesEn = $this->buildAliasesEn($labelEn);
        $aliasesMr = array_values(array_unique(array_filter([$labelMr])));
        $ocr = $this->buildOcrVariants($labelEn, $aliasesEn);

        return [
            'entity_type' => $entityType,
            'key' => $key,
            'scope' => $scope,
            'label_en' => $labelEn,
            'label_mr' => $labelMr,
            'aliases_en' => $aliasesEn,
            'aliases_mr' => $aliasesMr,
            'ocr_variants' => $ocr,
        ];
    }

    private function marathiForEnglish(string $labelEn): string
    {
        $mr = RomanToDevanagariApprox::toDevanagari($labelEn);
        if (! RomanToDevanagariApprox::containsDevanagari($mr) && function_exists('transliterator_transliterate')) {
            $tr = @transliterator_transliterate('Latin-Devanagari', $labelEn);
            if (is_string($tr) && $tr !== '' && RomanToDevanagariApprox::containsDevanagari($tr)) {
                return preg_replace('/\s+/u', ' ', trim($tr)) ?? $tr;
            }
        }

        return $mr;
    }

    /**
     * @return list<string>
     */
    private function buildAliasesEn(string $labelEn): array
    {
        $out = [$labelEn];
        $lower = mb_strtolower($labelEn);
        if ($lower !== $labelEn) {
            $out[] = $lower;
        }
        $trim = trim($labelEn);
        $fold = preg_replace('/\s+/u', ' ', $trim) ?? $trim;
        if ($fold !== $labelEn) {
            $out[] = $fold;
        }
        if (preg_match('/shaikh|sheikh|shekh/i', $labelEn)) {
            $out[] = (string) preg_replace('/shaikh|sheikh|shekh/i', 'Shaikh', $labelEn);
            $out[] = (string) preg_replace('/shaikh|sheikh|shekh/i', 'Sheikh', $labelEn);
        }
        if (stripos($labelEn, 'lingayat') !== false) {
            $out[] = (string) preg_replace('/lingayath/i', 'Lingayat', $labelEn);
            $out[] = (string) preg_replace('/lingayat/i', 'Lingayath', $labelEn);
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * @param  list<string>  $aliasesEn
     * @return list<string>
     */
    private function buildOcrVariants(string $labelEn, array $aliasesEn): array
    {
        $v = $aliasesEn;
        foreach ($aliasesEn as $a) {
            $v[] = str_replace(['0', 'O'], ['O', '0'], $a);
            $v[] = preg_replace('/\s+/u', '  ', $a) ?? $a;
        }

        return array_values(array_unique(array_filter($v)));
    }
}
