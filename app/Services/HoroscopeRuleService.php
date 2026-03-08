<?php

namespace App\Services;

use App\Models\MasterNakshatraAttribute;
use App\Models\MasterNakshatraPadaRashiRule;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes horoscope dependency lookup, autofill, and validation.
 * No external API; no calculation from DOB/birth_time/birth_place.
 * Rules: nakshatra_id + charan -> rashi_id; nakshatra_id -> gan_id, nadi_id, yoni_id.
 * Ashta-Koota (36 Gun Milan): Varna, Vashya, Tara, Graha Maitri, Bhakoot are calculated from rashi/nakshatra (no user inputs).
 *
 * Validation mode: save allowed with persistent mismatch warning (Option 2).
 * Mismatches return warning payload for UI; do NOT throw.
 */
class HoroscopeRuleService
{
    /** Rashi key => zodiac position 1-12 for Bhakoot. */
    private const RASHI_POSITION = [
        'mesha' => 1, 'vrishabha' => 2, 'mithuna' => 3, 'karka' => 4, 'simha' => 5, 'kanya' => 6,
        'tula' => 7, 'vrishchika' => 8, 'dhanu' => 9, 'makara' => 10, 'kumbha' => 11, 'meena' => 12,
    ];

    /** Tara number 1-9 => points (max 3 for Ashta-Koota). 1=Janma, 3=Vipat, 5=Pratyak, 7=Vadha = 0; others = 1.5 each direction. */
    private const TARA_POINTS = [1 => 0, 2 => 1.5, 3 => 0, 4 => 1.5, 5 => 0, 6 => 1.5, 7 => 0, 8 => 1.5, 9 => 1.5];

    /** Tara number => label. */
    private const TARA_LABELS = [
        1 => 'Janma', 2 => 'Sampat', 3 => 'Vipat', 4 => 'Kshema', 5 => 'Pratyak',
        6 => 'Sadhak', 7 => 'Vadha', 8 => 'Mitra', 9 => 'Atimitra',
    ];

    /** Graha Maitri: lord_key => [lord_key => points]. 5=same, 4=friend, 3=neutral, 0=enemy. */
    private const GRAHA_MAITRI = [
        'sun' => ['sun' => 5, 'moon' => 4, 'mars' => 4, 'mercury' => 3, 'venus' => 0, 'jupiter' => 4, 'saturn' => 0],
        'moon' => ['sun' => 4, 'moon' => 5, 'mars' => 3, 'mercury' => 3, 'venus' => 3, 'jupiter' => 3, 'saturn' => 3],
        'mars' => ['sun' => 4, 'moon' => 3, 'mars' => 5, 'mercury' => 0, 'venus' => 0, 'jupiter' => 4, 'saturn' => 3],
        'mercury' => ['sun' => 3, 'moon' => 3, 'mars' => 0, 'mercury' => 5, 'venus' => 4, 'jupiter' => 3, 'saturn' => 3],
        'venus' => ['sun' => 0, 'moon' => 3, 'mars' => 0, 'mercury' => 4, 'venus' => 5, 'jupiter' => 3, 'saturn' => 4],
        'jupiter' => ['sun' => 4, 'moon' => 3, 'mars' => 4, 'mercury' => 0, 'venus' => 0, 'jupiter' => 5, 'saturn' => 3],
        'saturn' => ['sun' => 0, 'moon' => 3, 'mars' => 3, 'mercury' => 3, 'venus' => 4, 'jupiter' => 3, 'saturn' => 5],
    ];
    /**
     * Exact active rule for nakshatra + charan. Returns null if nakshatra or charan missing.
     */
    public function findRashiRule(?int $nakshatraId, ?int $charan): ?MasterNakshatraPadaRashiRule
    {
        if ($nakshatraId === null || $charan === null || $charan < 1 || $charan > 4) {
            return null;
        }

        return MasterNakshatraPadaRashiRule::where('nakshatra_id', $nakshatraId)
            ->where('charan', (int) $charan)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Active nakshatra attributes row. Returns null if nakshatra missing.
     */
    public function findNakshatraAttributes(?int $nakshatraId): ?MasterNakshatraAttribute
    {
        if ($nakshatraId === null) {
            return null;
        }

        return MasterNakshatraAttribute::where('nakshatra_id', $nakshatraId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Expected values from rules for autofill. Never overwrites non-blank in this method.
     *
     * @return array{rashi_id: ?int, gan_id: ?int, nadi_id: ?int, yoni_id: ?int, matched_rashi_rule: bool, matched_nakshatra_attributes: bool}
     */
    public function getAutofillValues(?int $nakshatraId, ?int $charan): array
    {
        $rashiRule = $this->findRashiRule($nakshatraId, $charan);
        $attrs = $this->findNakshatraAttributes($nakshatraId);

        return [
            'rashi_id' => $rashiRule?->rashi_id,
            'gan_id' => $attrs?->gan_id,
            'nadi_id' => $attrs?->nadi_id,
            'yoni_id' => $attrs?->yoni_id,
            'matched_rashi_rule' => $rashiRule !== null,
            'matched_nakshatra_attributes' => $attrs !== null,
        ];
    }

    /**
     * Validate user selection against rules. Returns warnings and expected values.
     * Used for warning-mode UX: save allowed; mismatches return warning payload (no exception).
     *
     * @return array{warnings: array<string, string>, expected: array{rashi_id: ?int, gan_id: ?int, nadi_id: ?int, yoni_id: ?int}, matched: bool}
     */
    public function validateSelection(
        ?int $nakshatraId,
        ?int $charan,
        ?int $rashiId,
        ?int $ganId,
        ?int $nadiId,
        ?int $yoniId
    ): array {
        $warnings = [];
        $expected = [
            'rashi_id' => null,
            'gan_id' => null,
            'nadi_id' => null,
            'yoni_id' => null,
        ];

        $rashiRule = $this->findRashiRule($nakshatraId, $charan);
        if ($rashiRule !== null) {
            $expected['rashi_id'] = $rashiRule->rashi_id;
            if ($rashiId !== null && $rashiId !== 0 && (int) $rashiId !== (int) $rashiRule->rashi_id) {
                $warnings['rashi_id'] = 'Selected Rashi does not match the standard for this Nakshatra + Charan.';
            }
        }

        $attrs = $this->findNakshatraAttributes($nakshatraId);
        if ($attrs !== null) {
            $expected['gan_id'] = $attrs->gan_id;
            $expected['nadi_id'] = $attrs->nadi_id;
            $expected['yoni_id'] = $attrs->yoni_id;
            if ($attrs->gan_id !== null && $ganId !== null && $ganId !== 0 && (int) $ganId !== (int) $attrs->gan_id) {
                $warnings['gan_id'] = 'Selected Gan does not match the standard for this Nakshatra.';
            }
            if ($attrs->nadi_id !== null && $nadiId !== null && $nadiId !== 0 && (int) $nadiId !== (int) $attrs->nadi_id) {
                $warnings['nadi_id'] = 'Selected Nadi does not match the standard for this Nakshatra.';
            }
            if ($attrs->yoni_id !== null && $yoniId !== null && $yoniId !== 0 && (int) $yoniId !== (int) $attrs->yoni_id) {
                $warnings['yoni_id'] = 'Selected Yoni does not match the standard for this Nakshatra.';
            }
        }

        $matched = count($warnings) === 0;

        return [
            'warnings' => $warnings,
            'expected' => $expected,
            'matched' => $matched,
        ];
    }

    /** Marathi messages for dependency mismatch (warning-mode UI). */
    private const WARNING_MESSAGES = [
        'rashi_id' => 'तुम्ही निवडलेल्या नक्षत्र + चरणसाठी ही रास योग्य नाही.',
        'gan_id' => 'तुम्ही निवडलेल्या नक्षत्रासाठी हा गण योग्य नाही.',
        'nadi_id' => 'तुम्ही निवडलेल्या नक्षत्रासाठी ही नाडी योग्य नाही.',
        'yoni_id' => 'तुम्ही निवडलेल्या नक्षत्रासाठी ही योनी योग्य नाही.',
    ];

    /**
     * Validation warnings for UI: Marathi messages + expected options with id and label.
     * Recompute from saved row on every section/preview render for persistent warning state.
     *
     * @param  array{rashi_id: ?int, nakshatra_id: ?int, charan: ?int, gan_id: ?int, nadi_id: ?int, yoni_id: ?int}  $row
     * @return array{matched: bool, warnings: array<string, array{message: string, expected: array<array{id: int, label: string}>}>, expected: array{rashi_id: ?int, gan_id: ?int, nadi_id: ?int, yoni_id: ?int}}
     */
    public function getValidationWarningsForUI(array $row): array
    {
        $nakshatraId = isset($row['nakshatra_id']) && $row['nakshatra_id'] !== '' ? (int) $row['nakshatra_id'] : null;
        $charan = isset($row['charan']) && $row['charan'] !== '' ? (int) $row['charan'] : null;
        if ($charan !== null && ($charan < 1 || $charan > 4)) {
            $charan = null;
        }
        $rashiId = ! empty($row['rashi_id']) ? (int) $row['rashi_id'] : null;
        $ganId = ! empty($row['gan_id']) ? (int) $row['gan_id'] : null;
        $nadiId = ! empty($row['nadi_id']) ? (int) $row['nadi_id'] : null;
        $yoniId = ! empty($row['yoni_id']) ? (int) $row['yoni_id'] : null;

        $validation = $this->validateSelection($nakshatraId, $charan, $rashiId, $ganId, $nadiId, $yoniId);
        $warnings = [];
        $expectedIds = [
            'rashi_id' => $validation['expected']['rashi_id'],
            'gan_id' => $validation['expected']['gan_id'],
            'nadi_id' => $validation['expected']['nadi_id'],
            'yoni_id' => $validation['expected']['yoni_id'],
        ];
        foreach ($validation['warnings'] as $field => $message) {
            $expectedId = $expectedIds[$field] ?? null;
            $expectedOptions = [];
            if ($expectedId !== null) {
                $label = $this->resolveLabelForField($field, $expectedId);
                $expectedOptions[] = ['id' => $expectedId, 'label' => $label];
            }
            $warnings[$field] = [
                'message' => self::WARNING_MESSAGES[$field] ?? $message,
                'expected' => $expectedOptions,
            ];
        }
        return [
            'matched' => $validation['matched'],
            'warnings' => $warnings,
            'expected' => $validation['expected'],
        ];
    }

    private function resolveLabelForField(string $field, int $id): string
    {
        $model = match ($field) {
            'rashi_id' => \App\Models\MasterRashi::find($id),
            'gan_id' => \App\Models\MasterGan::find($id),
            'nadi_id' => \App\Models\MasterNadi::find($id),
            'yoni_id' => \App\Models\MasterYoni::find($id),
            default => null,
        };
        $label = $model ? ($model->label ?? $model->name ?? (string) $id) : (string) $id;
        if ($field === 'yoni_id') {
            $label = preg_replace('/\s*\(Male\)\s*$/i', '', $label);
            $label = preg_replace('/\s*\(Female\)\s*$/i', '', $label);
            $label = trim($label);
        }
        return $label;
    }

    /**
     * Apply autofill to payload: set dependent fields only when current value is blank.
     * Blank fields get rule value; non-blank (including mismatched) are left as-is so save-with-warning works.
     * mangal_dosh_type_id, devak, kul, gotra remain manual and are never overwritten.
     */
    public function applyAutofillToPayload(array $payload): array
    {
        $nakshatraId = isset($payload['nakshatra_id']) && $payload['nakshatra_id'] !== '' ? (int) $payload['nakshatra_id'] : null;
        $charan = isset($payload['charan']) && $payload['charan'] !== '' ? (int) $payload['charan'] : null;
        if ($charan !== null && ($charan < 1 || $charan > 4)) {
            $charan = null;
        }

        $autofill = $this->getAutofillValues($nakshatraId, $charan);

        if ($nakshatraId !== null) {
            if (($payload['gan_id'] ?? null) === null || $payload['gan_id'] === '') {
                if ($autofill['gan_id'] !== null) {
                    $payload['gan_id'] = $autofill['gan_id'];
                }
            }
            if (($payload['nadi_id'] ?? null) === null || $payload['nadi_id'] === '') {
                if ($autofill['nadi_id'] !== null) {
                    $payload['nadi_id'] = $autofill['nadi_id'];
                }
            }
            if (($payload['yoni_id'] ?? null) === null || $payload['yoni_id'] === '') {
                if ($autofill['yoni_id'] !== null) {
                    $payload['yoni_id'] = $autofill['yoni_id'];
                }
            }
        }
        if ($nakshatraId !== null && $charan !== null && (($payload['rashi_id'] ?? null) === null || $payload['rashi_id'] === '')) {
            if ($autofill['rashi_id'] !== null) {
                $payload['rashi_id'] = $autofill['rashi_id'];
            }
        }

        return $payload;
    }

    /**
     * Distinct rashi IDs for a nakshatra (across all 4 charans). For UI: filter rashi dropdown when only nakshatra selected.
     *
     * @return int[]
     */
    public function getDistinctRashiIdsForNakshatra(int $nakshatraId): array
    {
        return MasterNakshatraPadaRashiRule::where('nakshatra_id', $nakshatraId)
            ->where('is_active', true)
            ->distinct()
            ->pluck('rashi_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Nakshatra IDs that have at least one pada in the given rashi. For UI: filter nakshatra dropdown when rashi selected first.
     *
     * @return int[]
     */
    public function getNakshatraIdsForRashi(int $rashiId): array
    {
        return MasterNakshatraPadaRashiRule::where('rashi_id', $rashiId)
            ->where('is_active', true)
            ->distinct()
            ->pluck('nakshatra_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Valid charans (1-4) for nakshatra + rashi. For UI: filter charan dropdown when both nakshatra and rashi selected.
     *
     * @return int[]
     */
    public function getValidCharansForNakshatraRashi(int $nakshatraId, int $rashiId): array
    {
        return MasterNakshatraPadaRashiRule::where('nakshatra_id', $nakshatraId)
            ->where('rashi_id', $rashiId)
            ->where('is_active', true)
            ->orderBy('charan')
            ->pluck('charan')
            ->map(fn ($c) => (int) $c)
            ->values()
            ->all();
    }

    /**
     * All active rules for front-end live dependency (no API call).
     * Keys: rashi_rules, nakshatra_attributes, distinct_rashi_ids_by_nakshatra, nakshatra_ids_by_rashi.
     */
    public function getRulesForFrontend(): array
    {
        $rashiRules = MasterNakshatraPadaRashiRule::where('is_active', true)
            ->get(['nakshatra_id', 'charan', 'rashi_id'])
            ->map(fn ($r) => ['nakshatra_id' => (int) $r->nakshatra_id, 'charan' => (int) $r->charan, 'rashi_id' => (int) $r->rashi_id])
            ->values()
            ->all();
        $nakshatraAttributes = MasterNakshatraAttribute::where('is_active', true)
            ->get(['nakshatra_id', 'gan_id', 'nadi_id', 'yoni_id'])
            ->map(fn ($a) => [
                'nakshatra_id' => (int) $a->nakshatra_id,
                'gan_id' => $a->gan_id !== null ? (int) $a->gan_id : null,
                'nadi_id' => $a->nadi_id !== null ? (int) $a->nadi_id : null,
                'yoni_id' => $a->yoni_id !== null ? (int) $a->yoni_id : null,
            ])
            ->values()
            ->all();

        $nakshatraIds = array_unique(array_column($rashiRules, 'nakshatra_id'));
        $distinctRashiIdsByNakshatra = [];
        foreach ($nakshatraIds as $nid) {
            $distinctRashiIdsByNakshatra[(string) $nid] = $this->getDistinctRashiIdsForNakshatra((int) $nid);
        }

        $rashiIds = array_unique(array_column($rashiRules, 'rashi_id'));
        $nakshatraIdsByRashi = [];
        foreach ($rashiIds as $rid) {
            $nakshatraIdsByRashi[(string) $rid] = $this->getNakshatraIdsForRashi((int) $rid);
        }

        return [
            'rashi_rules' => $rashiRules,
            'nakshatra_attributes' => $nakshatraAttributes,
            'distinct_rashi_ids_by_nakshatra' => $distinctRashiIdsByNakshatra,
            'nakshatra_ids_by_rashi' => $nakshatraIdsByRashi,
        ];
    }

    /**
     * Ashta-Koota display for frontend: per rashi_id, labels for Varna, Vashya, Rashi Lord (read-only in UI).
     * Keyed by rashi id (string) => ['varna' => label, 'vashya' => label, 'rashi_lord' => label].
     *
     * @return array<string, array{varna: string, vashya: string, rashi_lord: string}>
     */
    public function getRashiAshtakootaForFrontend(): array
    {
        $rashis = DB::table('master_rashis')->where('is_active', true)->get(['id', 'varna_id', 'vashya_id', 'rashi_lord_id']);
        $varnaIds = $rashis->pluck('varna_id')->filter()->unique()->values()->all();
        $vashyaIds = $rashis->pluck('vashya_id')->filter()->unique()->values()->all();
        $lordIds = $rashis->pluck('rashi_lord_id')->filter()->unique()->values()->all();

        $varnas = $varnaIds ? DB::table('master_varnas')->whereIn('id', $varnaIds)->where('is_active', true)->pluck('label', 'id') : collect();
        $vashyas = $vashyaIds ? DB::table('master_vashyas')->whereIn('id', $vashyaIds)->where('is_active', true)->pluck('label', 'id') : collect();
        $lords = $lordIds ? DB::table('master_rashi_lords')->whereIn('id', $lordIds)->where('is_active', true)->pluck('label', 'id') : collect();

        $out = [];
        foreach ($rashis as $r) {
            $out[(string) $r->id] = [
                'varna' => $r->varna_id ? ($varnas[$r->varna_id] ?? '—') : '—',
                'vashya' => $r->vashya_id ? ($vashyas[$r->vashya_id] ?? '—') : '—',
                'rashi_lord' => $r->rashi_lord_id ? ($lords[$r->rashi_lord_id] ?? '—') : '—',
            ];
        }
        return $out;
    }

    // ---------- Ashta-Koota / 36 Gun Milan (calculated only; no user inputs) ----------

    /**
     * Varna depends only on Rashi. Returns varna row (id, key, label) or null.
     *
     * @return object{id: int, key: string, label: string}|null
     */
    public function getVarnaByRashi(?int $rashiId): ?object
    {
        if ($rashiId === null) {
            return null;
        }
        $rashi = DB::table('master_rashis')->where('id', $rashiId)->first();
        if (! $rashi || ! $rashi->varna_id) {
            return null;
        }
        $varna = DB::table('master_varnas')->where('id', $rashi->varna_id)->where('is_active', true)->first();

        return $varna ? (object) ['id' => (int) $varna->id, 'key' => $varna->key, 'label' => $varna->label] : null;
    }

    /**
     * Vashya depends only on Rashi. Returns vashya row (id, key, label) or null.
     *
     * @return object{id: int, key: string, label: string}|null
     */
    public function getVashyaByRashi(?int $rashiId): ?object
    {
        if ($rashiId === null) {
            return null;
        }
        $rashi = DB::table('master_rashis')->where('id', $rashiId)->first();
        if (! $rashi || ! $rashi->vashya_id) {
            return null;
        }
        $vashya = DB::table('master_vashyas')->where('id', $rashi->vashya_id)->where('is_active', true)->first();

        return $vashya ? (object) ['id' => (int) $vashya->id, 'key' => $vashya->key, 'label' => $vashya->label] : null;
    }

    /**
     * Rashi lord (for Graha Maitri). Depends only on Rashi. Returns lord row (id, key, label) or null.
     *
     * @return object{id: int, key: string, label: string}|null
     */
    public function getRashiLord(?int $rashiId): ?object
    {
        if ($rashiId === null) {
            return null;
        }
        $rashi = DB::table('master_rashis')->where('id', $rashiId)->first();
        if (! $rashi || ! $rashi->rashi_lord_id) {
            return null;
        }
        $lord = DB::table('master_rashi_lords')->where('id', $rashi->rashi_lord_id)->where('is_active', true)->first();

        return $lord ? (object) ['id' => (int) $lord->id, 'key' => $lord->key, 'label' => $lord->label] : null;
    }

    /**
     * Tara depends only on nakshatra number. Calculated between bride and groom; do not store in profile.
     * Count from bride's nakshatra to groom's (forward), then (count - 1) % 9 + 1 gives Tara 1-9.
     *
     * @return array{tara_number: int|null, tara_label: string|null, points: float, bride_number: int|null, groom_number: int|null}
     */
    public function calculateTara(?int $brideNakshatraId, ?int $groomNakshatraId): array
    {
        $out = ['tara_number' => null, 'tara_label' => null, 'points' => 0.0, 'bride_number' => null, 'groom_number' => null];
        if ($brideNakshatraId === null || $groomNakshatraId === null) {
            return $out;
        }
        $b = DB::table('master_nakshatras')->where('id', $brideNakshatraId)->value('nakshatra_number');
        $g = DB::table('master_nakshatras')->where('id', $groomNakshatraId)->value('nakshatra_number');
        if ($b === null || $g === null || $b < 1 || $b > 27 || $g < 1 || $g > 27) {
            return $out;
        }
        $out['bride_number'] = (int) $b;
        $out['groom_number'] = (int) $g;
        $count = ($g - $b + 27) % 27;
        if ($count === 0) {
            $count = 27;
        }
        $tara = ($count - 1) % 9 + 1;
        $out['tara_number'] = $tara;
        $out['tara_label'] = self::TARA_LABELS[$tara] ?? null;
        $out['points'] = (float) (self::TARA_POINTS[$tara] ?? 0);

        return $out;
    }

    /**
     * Bhakoot depends on rashi pair (bride + groom). Calculated dynamically; do not store in profile.
     * 2/12, 5/9, 6/8 = 0 points (Bhakoot Dosha); others = 7 points. Max 7.
     *
     * @return array{points: int, is_dosha: bool, bride_position: int|null, groom_position: int|null}
     */
    public function calculateBhakoot(?int $brideRashiId, ?int $groomRashiId): array
    {
        $out = ['points' => 0, 'is_dosha' => true, 'bride_position' => null, 'groom_position' => null];
        if ($brideRashiId === null || $groomRashiId === null) {
            return $out;
        }
        $brideKey = DB::table('master_rashis')->where('id', $brideRashiId)->value('key');
        $groomKey = DB::table('master_rashis')->where('id', $groomRashiId)->value('key');
        $p1 = self::RASHI_POSITION[$brideKey] ?? null;
        $p2 = self::RASHI_POSITION[$groomKey] ?? null;
        if ($p1 === null || $p2 === null) {
            return $out;
        }
        $out['bride_position'] = $p1;
        $out['groom_position'] = $p2;
        $diff = ($p2 - $p1 + 12) % 12;
        if ($diff === 0) {
            $out['points'] = 7;
            $out['is_dosha'] = false;

            return $out;
        }
        $doshaPositions = [1, 4, 5, 7, 8, 11];
        $out['is_dosha'] = in_array($diff, $doshaPositions, true);
        $out['points'] = $out['is_dosha'] ? 0 : 7;

        return $out;
    }

    /**
     * Graha Maitri depends on rashi lords (bride and groom). Same lord=5, friend=4, neutral=3, enemy=0. Max 5.
     *
     * @return array{points: int, bride_lord_id: int|null, groom_lord_id: int|null, bride_lord_key: string|null, groom_lord_key: string|null}
     */
    public function calculateGrahaMaitri(?int $brideRashiId, ?int $groomRashiId): array
    {
        $out = ['points' => 0, 'bride_lord_id' => null, 'groom_lord_id' => null, 'bride_lord_key' => null, 'groom_lord_key' => null];
        $brideLord = $this->getRashiLord($brideRashiId);
        $groomLord = $this->getRashiLord($groomRashiId);
        if ($brideLord === null || $groomLord === null) {
            return $out;
        }
        $out['bride_lord_id'] = $brideLord->id;
        $out['groom_lord_id'] = $groomLord->id;
        $out['bride_lord_key'] = $brideLord->key;
        $out['groom_lord_key'] = $groomLord->key;
        $out['points'] = (int) (self::GRAHA_MAITRI[$brideLord->key][$groomLord->key] ?? 0);

        return $out;
    }
}
