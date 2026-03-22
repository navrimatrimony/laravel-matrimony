<?php

namespace App\Services\MasterData;

use App\Models\Caste;
use App\Models\CasteAlias;
use App\Models\Religion;
use App\Models\ReligionAlias;
use App\Models\SubCaste;
use App\Models\SubCasteAlias;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;

/**
 * OCR-safe resolution of religion/caste/sub-caste text to master IDs.
 */
class ReligionCasteSubCasteResolver
{
    public function __construct(
        private ReligionCasteSubcasteSlugger $slugger
    ) {}

    /**
     * @return array{
     *   religion_id: ?int,
     *   caste_id: ?int,
     *   sub_caste_id: ?int,
     *   religion_match: ?string,
     *   caste_match: ?string,
     *   sub_caste_match: ?string,
     *   religion_confidence: float,
     *   caste_confidence: float,
     *   sub_caste_confidence: float
     * }
     */
    public function resolve(
        ?string $rawReligion,
        ?string $rawCaste,
        ?string $rawSubCaste,
        ?int $existingReligionId = null,
        ?int $existingCasteId = null,
        ?int $existingSubCasteId = null
    ): array {
        $out = [
            'religion_id' => $existingReligionId,
            'caste_id' => $existingCasteId,
            'sub_caste_id' => $existingSubCasteId,
            'religion_match' => null,
            'caste_match' => null,
            'sub_caste_match' => null,
            'religion_confidence' => $existingReligionId ? 1.0 : 0.0,
            'caste_confidence' => $existingCasteId ? 1.0 : 0.0,
            'sub_caste_confidence' => $existingSubCasteId ? 1.0 : 0.0,
        ];

        $rRaw = $rawReligion !== null ? trim($rawReligion) : '';
        $cRaw = $rawCaste !== null ? trim($rawCaste) : '';
        $sRaw = $rawSubCaste !== null ? trim($rawSubCaste) : '';

        $minAccept = 0.82;

        if ($rRaw !== '') {
            $rel = $this->resolveReligion($rRaw);
            if ($rel['id'] !== null && $rel['confidence'] >= $minAccept) {
                $out['religion_id'] = $rel['id'];
                $out['religion_match'] = $rel['mode'];
                $out['religion_confidence'] = $rel['confidence'];
            } elseif ($existingReligionId) {
                $out['religion_id'] = $existingReligionId;
                $out['religion_confidence'] = 1.0;
            } else {
                $out['religion_id'] = null;
                $out['religion_confidence'] = $rel['confidence'];
            }
        }

        $religionId = $out['religion_id'];

        if ($religionId !== null && $cRaw !== '') {
            $cas = $this->resolveCaste($religionId, $cRaw);
            if ($cas['id'] !== null && $cas['confidence'] >= $minAccept) {
                $out['caste_id'] = $cas['id'];
                $out['caste_match'] = $cas['mode'];
                $out['caste_confidence'] = $cas['confidence'];
            } elseif ($existingCasteId) {
                $out['caste_id'] = $existingCasteId;
                $out['caste_confidence'] = 1.0;
            } else {
                $out['caste_id'] = null;
                $out['caste_confidence'] = $cas['confidence'];
            }
        }

        $casteId = $out['caste_id'];

        if ($casteId !== null && $sRaw !== '') {
            $sub = $this->resolveSubCaste($casteId, $sRaw);
            if ($sub['id'] !== null && $sub['confidence'] >= $minAccept) {
                $out['sub_caste_id'] = $sub['id'];
                $out['sub_caste_match'] = $sub['mode'];
                $out['sub_caste_confidence'] = $sub['confidence'];
            } elseif ($existingSubCasteId) {
                $out['sub_caste_id'] = $existingSubCasteId;
                $out['sub_caste_confidence'] = 1.0;
            } else {
                $out['sub_caste_id'] = null;
                $out['sub_caste_confidence'] = $sub['confidence'];
            }
        }

        return $out;
    }

    /**
     * @return array{id: ?int, mode: ?string, confidence: float}
     */
    private function resolveReligion(string $raw): array
    {
        $norm = $this->normalize($raw);
        if ($norm === '') {
            return ['id' => null, 'mode' => null, 'confidence' => 0.0];
        }

        try {
            $key = $this->slugger->makeKey($raw);
        } catch (\InvalidArgumentException) {
            $key = null;
        }

        if ($key) {
            $byKey = Religion::where('is_active', true)->where('key', $key)->first();
            if ($byKey) {
                return ['id' => $byKey->id, 'mode' => 'key', 'confidence' => 0.98];
            }
        }

        $byEn = Religion::where('is_active', true)->whereRaw('LOWER(label_en) = ?', [mb_strtolower($raw)])->first();
        if ($byEn) {
            return ['id' => $byEn->id, 'mode' => 'label_en', 'confidence' => 0.97];
        }
        $byMr = Religion::where('is_active', true)->whereRaw('LOWER(label_mr) = ?', [mb_strtolower($raw)])->first();
        if ($byMr) {
            return ['id' => $byMr->id, 'mode' => 'label_mr', 'confidence' => 0.97];
        }
        $byLabel = Religion::where('is_active', true)->whereRaw('LOWER(label) = ?', [mb_strtolower($raw)])->first();
        if ($byLabel) {
            return ['id' => $byLabel->id, 'mode' => 'label_legacy', 'confidence' => 0.96];
        }

        $alias = ReligionAlias::query()
            ->where('normalized_alias', $norm)
            ->first();
        if ($alias) {
            return ['id' => $alias->religion_id, 'mode' => 'alias_'.$alias->alias_type, 'confidence' => 0.92];
        }

        $fuzzy = $this->fuzzyReligion($norm);
        if ($fuzzy['id'] !== null) {
            return $fuzzy;
        }

        return ['id' => null, 'mode' => null, 'confidence' => 0.0];
    }

    /**
     * @return array{id: ?int, mode: ?string, confidence: float}
     */
    private function resolveCaste(int $religionId, string $raw): array
    {
        $norm = $this->normalize($raw);
        if ($norm === '') {
            return ['id' => null, 'mode' => null, 'confidence' => 0.0];
        }

        try {
            $key = $this->slugger->makeKey($raw);
        } catch (\InvalidArgumentException) {
            $key = null;
        }

        if ($key) {
            $byKey = Caste::where('religion_id', $religionId)->where('is_active', true)->where('key', $key)->first();
            if ($byKey) {
                return ['id' => $byKey->id, 'mode' => 'key', 'confidence' => 0.98];
            }
        }

        $byEn = Caste::where('religion_id', $religionId)->where('is_active', true)->whereRaw('LOWER(label_en) = ?', [mb_strtolower($raw)])->first();
        if ($byEn) {
            return ['id' => $byEn->id, 'mode' => 'label_en', 'confidence' => 0.97];
        }
        $byMr = Caste::where('religion_id', $religionId)->where('is_active', true)->whereRaw('LOWER(label_mr) = ?', [mb_strtolower($raw)])->first();
        if ($byMr) {
            return ['id' => $byMr->id, 'mode' => 'label_mr', 'confidence' => 0.97];
        }
        $byLabel = Caste::where('religion_id', $religionId)->where('is_active', true)->whereRaw('LOWER(label) = ?', [mb_strtolower($raw)])->first();
        if ($byLabel) {
            return ['id' => $byLabel->id, 'mode' => 'label_legacy', 'confidence' => 0.96];
        }

        $alias = CasteAlias::query()
            ->whereHas('caste', fn ($q) => $q->where('religion_id', $religionId))
            ->where('normalized_alias', $norm)
            ->first();
        if ($alias) {
            return ['id' => $alias->caste_id, 'mode' => 'alias_'.$alias->alias_type, 'confidence' => 0.9];
        }

        $fuzzy = $this->fuzzyCaste($religionId, $norm);
        if ($fuzzy['id'] !== null) {
            return $fuzzy;
        }

        return ['id' => null, 'mode' => null, 'confidence' => 0.0];
    }

    /**
     * @return array{id: ?int, mode: ?string, confidence: float}
     */
    private function resolveSubCaste(int $casteId, string $raw): array
    {
        $norm = $this->normalize($raw);
        if ($norm === '') {
            return ['id' => null, 'mode' => null, 'confidence' => 0.0];
        }

        try {
            $key = $this->slugger->makeKey($raw);
        } catch (\InvalidArgumentException) {
            $key = null;
        }

        if ($key) {
            $byKey = SubCaste::where('caste_id', $casteId)->where('is_active', true)->where('status', 'approved')->where('key', $key)->first();
            if ($byKey) {
                return ['id' => $byKey->id, 'mode' => 'key', 'confidence' => 0.98];
            }
        }

        $byEn = SubCaste::where('caste_id', $casteId)->where('is_active', true)->where('status', 'approved')->whereRaw('LOWER(label_en) = ?', [mb_strtolower($raw)])->first();
        if ($byEn) {
            return ['id' => $byEn->id, 'mode' => 'label_en', 'confidence' => 0.97];
        }
        $byMr = SubCaste::where('caste_id', $casteId)->where('is_active', true)->where('status', 'approved')->whereRaw('LOWER(label_mr) = ?', [mb_strtolower($raw)])->first();
        if ($byMr) {
            return ['id' => $byMr->id, 'mode' => 'label_mr', 'confidence' => 0.97];
        }
        $byLabel = SubCaste::where('caste_id', $casteId)->where('is_active', true)->where('status', 'approved')->whereRaw('LOWER(label) = ?', [mb_strtolower($raw)])->first();
        if ($byLabel) {
            return ['id' => $byLabel->id, 'mode' => 'label_legacy', 'confidence' => 0.96];
        }

        $alias = SubCasteAlias::query()
            ->whereHas('subCaste', fn ($q) => $q->where('caste_id', $casteId))
            ->where('normalized_alias', $norm)
            ->first();
        if ($alias) {
            return ['id' => $alias->sub_caste_id, 'mode' => 'alias_'.$alias->alias_type, 'confidence' => 0.9];
        }

        $fuzzy = $this->fuzzySubCaste($casteId, $norm);
        if ($fuzzy['id'] !== null) {
            return $fuzzy;
        }

        return ['id' => null, 'mode' => null, 'confidence' => 0.0];
    }

    private function normalize(string $s): string
    {
        $t = mb_strtolower(trim($s));
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    /**
     * @return array{id: ?int, mode: ?string, confidence: float}
     */
    private function fuzzyReligion(string $norm): array
    {
        if (mb_strlen($norm) < 3) {
            return ['id' => null, 'mode' => null, 'confidence' => 0.0];
        }

        $best = null;
        $bestPct = 0.0;
        foreach (Religion::where('is_active', true)->cursor() as $r) {
            foreach ([$r->label_en, $r->label_mr, $r->label] as $lbl) {
                if (! $lbl) {
                    continue;
                }
                $ln = $this->normalize((string) $lbl);
                similar_text($norm, $ln, $pct);
                if ($pct > $bestPct) {
                    $bestPct = $pct;
                    $best = $r;
                }
            }
        }

        if ($best && $bestPct >= 92.0) {
            return ['id' => $best->id, 'mode' => 'fuzzy', 'confidence' => min(0.88, $bestPct / 100)];
        }

        return ['id' => null, 'mode' => null, 'confidence' => 0.0];
    }

    /**
     * @return array{id: ?int, mode: ?string, confidence: float}
     */
    private function fuzzyCaste(int $religionId, string $norm): array
    {
        if (mb_strlen($norm) < 3) {
            return ['id' => null, 'mode' => null, 'confidence' => 0.0];
        }

        $best = null;
        $bestPct = 0.0;
        foreach (Caste::where('religion_id', $religionId)->where('is_active', true)->cursor() as $c) {
            foreach ([$c->label_en, $c->label_mr, $c->label] as $lbl) {
                if (! $lbl) {
                    continue;
                }
                $ln = $this->normalize((string) $lbl);
                similar_text($norm, $ln, $pct);
                if ($pct > $bestPct) {
                    $bestPct = $pct;
                    $best = $c;
                }
            }
        }

        if ($best && $bestPct >= 93.0) {
            return ['id' => $best->id, 'mode' => 'fuzzy', 'confidence' => min(0.87, $bestPct / 100)];
        }

        return ['id' => null, 'mode' => null, 'confidence' => 0.0];
    }

    /**
     * @return array{id: ?int, mode: ?string, confidence: float}
     */
    private function fuzzySubCaste(int $casteId, string $norm): array
    {
        if (mb_strlen($norm) < 2) {
            return ['id' => null, 'mode' => null, 'confidence' => 0.0];
        }

        $best = null;
        $bestPct = 0.0;
        foreach (SubCaste::where('caste_id', $casteId)->where('is_active', true)->where('status', 'approved')->cursor() as $s) {
            foreach ([$s->label_en, $s->label_mr, $s->label] as $lbl) {
                if (! $lbl) {
                    continue;
                }
                $ln = $this->normalize((string) $lbl);
                similar_text($norm, $ln, $pct);
                if ($pct > $bestPct) {
                    $bestPct = $pct;
                    $best = $s;
                }
            }
        }

        if ($best && $bestPct >= 92.0) {
            return ['id' => $best->id, 'mode' => 'fuzzy', 'confidence' => min(0.88, $bestPct / 100)];
        }

        return ['id' => null, 'mode' => null, 'confidence' => 0.0];
    }
}
