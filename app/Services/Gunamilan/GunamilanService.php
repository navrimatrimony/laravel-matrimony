<?php

namespace App\Services\Gunamilan;

use App\Models\MatrimonyProfile;
use App\Models\ProfileHoroscopeData;
use App\Services\HoroscopeRuleService;

/**
 * गुणमिलन / Gunamilan — the read-only 36-point Ashta-Koota engine.
 *
 * Two entry points:
 *
 * - {@see self::calculate()} for a single viewer/target pair (web report, mobile
 *   profile payload). Resolves bride/groom from gender, flattens both sides and
 *   compares.
 * - {@see self::kootaKeyFor()} + {@see self::compare()} for bulk work. Flatten
 *   each profile ONCE into a {@see GunamilanKootaKey}, then compare pairs — no
 *   database access at all per pair, which is what makes this safe to call
 *   inside the matching feed over hundreds of candidates.
 *
 * ## Reading the result
 *
 * `total_points` is ALWAYS numeric, so it must never be read on its own.
 * `computable` is the field that says whether that number means anything:
 *
 * - `computable === true`  → `total_points` is a real score and `is_compatible`
 *   is a real boolean (`total_points >= 18.0`, inclusive).
 * - `computable === false` → the inputs are incomplete. `total_points` is 0.0
 *   as an artefact, `is_compatible` is **null**, and `missing_fields` says what
 *   is absent. A profile with an empty horoscope row is UNKNOWN, never
 *   "incompatible" — treating 0/36 as a rejection would silently exclude every
 *   member who has not filled the horoscope section.
 *
 * `available` keeps its older, weaker meaning (both horoscope rows exist and the
 * bride/groom direction resolved) because the web report renders all eight
 * sections with per-section "missing" markers off it. New callers branch on
 * `computable`.
 */
class GunamilanService
{
    private const MAX_POINTS = 36.0;

    /**
     * 18 of 36 is compatible (owner decision, 2026-07-26).
     * INCLUSIVE — exactly 18.0 passes.
     */
    public const COMPATIBLE_THRESHOLD = 18.0;

    private const VARNA_RANKS = [
        'shudra' => 1,
        'vaishya' => 2,
        'kshatriya' => 3,
        'brahmin' => 4,
    ];

    /**
     * Vashya Koota, max 2. Complete 5x5 matrix — every canonical
     * `master_vashyas` key appears, including `keet`, which the old
     * compatible-pairs list omitted, so every Vrishchika (Scorpio) pairing fell
     * through to an undocumented 0.5 fallback. There is no fallback now: an
     * unrecognised key counts as missing, not as a half score.
     */
    private const VASHYA_POINTS = [
        'chatushpada' => ['chatushpada' => 2.0, 'manav' => 1.0, 'jalachar' => 1.0, 'vanchar' => 1.0, 'keet' => 1.0],
        'manav' => ['chatushpada' => 1.0, 'manav' => 2.0, 'jalachar' => 1.0, 'vanchar' => 0.5, 'keet' => 1.0],
        'jalachar' => ['chatushpada' => 1.0, 'manav' => 1.0, 'jalachar' => 2.0, 'vanchar' => 0.5, 'keet' => 1.0],
        'vanchar' => ['chatushpada' => 1.0, 'manav' => 0.5, 'jalachar' => 0.5, 'vanchar' => 2.0, 'keet' => 1.0],
        'keet' => ['chatushpada' => 1.0, 'manav' => 1.0, 'jalachar' => 1.0, 'vanchar' => 1.0, 'keet' => 2.0],
    ];

    /** Gana Koota, max 6. bride_gan:groom_gan. */
    private const GANA_POINTS = [
        'deva:deva' => 6.0,
        'deva:manav' => 5.0,
        'deva:rakshasa' => 1.0,
        'manav:deva' => 5.0,
        'manav:manav' => 6.0,
        'manav:rakshasa' => 0.0,
        'rakshasa:deva' => 0.0,
        'rakshasa:manav' => 0.0,
        'rakshasa:rakshasa' => 6.0,
    ];

    /**
     * The seven Yoni enemy pairs, in CANONICAL Sanskrit keys.
     *
     * This list used to be written in the retired English spellings while
     * `master_nakshatra_attributes` autofilled the Sanskrit ones, so the rule
     * never fired: an enemy pair scored the neutral 2 instead of 0, and the
     * same animal stored under two spellings failed the equality test and
     * scored 2 instead of 4. Four of the 36 points were wrong on every
     * autofilled profile. Keys arrive here already normalised by
     * {@see GunamilanMasterData::canonicalYoniKeyFor()}.
     */
    private const YONI_ENEMY_PAIRS = [
        'ashwa:mahish', 'mahish:ashwa',
        'gaja:singh', 'singh:gaja',
        'mesha:vanar', 'vanar:mesha',
        'sarpa:nakul', 'nakul:sarpa',
        'shwan:mrga', 'mrga:shwan',
        'marjar:mushak', 'mushak:marjar',
        'gau:vyaghra', 'vyaghra:gau',
    ];

    public function __construct(
        private readonly HoroscopeRuleService $rules,
        private readonly GunamilanMasterData $masters,
        private readonly MangalCompatibility $mangal,
    ) {
    }

    /**
     * Flatten one profile into its reusable koota key. Call once per profile
     * per run; the returned object can then be compared against any number of
     * other keys without touching the database.
     */
    public function kootaKeyFor(MatrimonyProfile $profile): GunamilanKootaKey
    {
        $profile->loadMissing(['gender', 'horoscope']);

        return GunamilanKootaKey::fromProfile($profile, $this->masters);
    }

    /** Flatten a horoscope row directly (bulk paths that already selected the rows). */
    public function kootaKeyForHoroscope(?ProfileHoroscopeData $horoscope, ?string $genderKey = null): GunamilanKootaKey
    {
        return GunamilanKootaKey::fromHoroscope($horoscope, $this->masters, $genderKey);
    }

    /**
     * Calculate the read-only Ashta-Koota result for a viewer/target pair.
     *
     * @return array{
     *     available: bool,
     *     computable: bool,
     *     state: string,
     *     total_points: float,
     *     max_points: float,
     *     threshold: float,
     *     is_compatible: bool|null,
     *     sections: array<int, array<string, mixed>>,
     *     nadi_dosha: bool|null,
     *     bhakoot_dosha: bool|null,
     *     mangal: array<string, mixed>,
     *     missing_fields: array<int, array{side: string, label: string}>,
     *     bride_profile_id: int|null,
     *     groom_profile_id: int|null
     * }
     */
    public function calculate(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile): array
    {
        $viewerProfile->loadMissing(['gender', 'horoscope']);
        $targetProfile->loadMissing(['gender', 'horoscope']);

        $pair = $this->resolveBrideGroom($viewerProfile, $targetProfile);
        $bride = $pair['bride'] ?? $viewerProfile;
        $groom = $pair['groom'] ?? $targetProfile;

        $brideKey = $pair !== null
            ? GunamilanKootaKey::fromProfile($bride, $this->masters)
            : GunamilanKootaKey::absent();
        $groomKey = $pair !== null
            ? GunamilanKootaKey::fromProfile($groom, $this->masters)
            : GunamilanKootaKey::absent();

        $result = $this->compare($brideKey, $groomKey, $pair !== null);

        return array_merge($result, [
            'bride_profile_id' => $pair ? (int) $bride->id : null,
            'groom_profile_id' => $pair ? (int) $groom->id : null,
        ]);
    }

    /**
     * Compare two already-flattened koota keys. Pure array math — zero queries.
     *
     * @param  bool  $directionResolved  false when bride/groom could not be told apart from gender.
     * @return array<string, mixed>
     */
    public function compare(GunamilanKootaKey $bride, GunamilanKootaKey $groom, bool $directionResolved = true): array
    {
        $missing = [];

        if (! $directionResolved) {
            $missing[] = ['side' => 'pair', 'label' => __('profile.gunamilan_missing_bride_direction')];
        }
        if ($directionResolved && ! $bride->hasHoroscopeRow) {
            $missing[] = ['side' => 'bride', 'label' => __('profile.gunamilan_missing_bride_horoscope')];
        }
        if ($directionResolved && ! $groom->hasHoroscopeRow) {
            $missing[] = ['side' => 'groom', 'label' => __('profile.gunamilan_missing_groom_horoscope')];
        }

        $sections = [
            $this->varnaSection($bride, $groom),
            $this->vashyaSection($bride, $groom),
            $this->taraSection($bride, $groom),
            $this->yoniSection($bride, $groom),
            $this->grahaMaitriSection($bride, $groom),
            $this->ganaSection($bride, $groom),
            $this->bhakootSection($bride, $groom),
            $this->nadiSection($bride, $groom),
        ];

        foreach ($sections as $section) {
            foreach (($section['missing'] ?? []) as $label) {
                $missing[] = ['side' => $section['key'], 'label' => $label];
            }
        }

        $total = round(array_reduce(
            $sections,
            fn (float $carry, array $section): float => $carry + (float) $section['points'],
            0.0
        ), 1);

        $missingFields = $this->uniqueMissing($missing);
        $available = $directionResolved && $bride->hasHoroscopeRow && $groom->hasHoroscopeRow;
        // A verdict exists only when every required input is actually present.
        // "0 points" and "unknown" must never collapse into the same answer.
        $computable = $available && $missingFields === [];

        $sectionsByKey = [];
        foreach ($sections as $section) {
            $sectionsByKey[$section['key']] = $section;
        }

        return [
            'available' => $available,
            'computable' => $computable,
            'state' => $computable ? 'computable' : 'not_computable',
            'total_points' => $total,
            'max_points' => self::MAX_POINTS,
            'threshold' => self::COMPATIBLE_THRESHOLD,
            'is_compatible' => $computable ? $total >= self::COMPATIBLE_THRESHOLD : null,
            'sections' => $sections,
            'nadi_dosha' => $sectionsByKey['nadi']['is_dosha'] ?? null,
            'bhakoot_dosha' => $sectionsByKey['bhakoot']['is_dosha'] ?? null,
            'mangal' => $this->mangal->compare($bride, $groom),
            'missing_fields' => $missingFields,
            'bride_profile_id' => $bride->profileId,
            'groom_profile_id' => $groom->profileId,
        ];
    }

    /**
     * @return array{bride: MatrimonyProfile, groom: MatrimonyProfile}|null
     */
    private function resolveBrideGroom(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile): ?array
    {
        $viewerGender = strtolower((string) ($viewerProfile->gender?->key ?? ''));
        $targetGender = strtolower((string) ($targetProfile->gender?->key ?? ''));

        if ($viewerGender === 'female' && $targetGender === 'male') {
            return ['bride' => $viewerProfile, 'groom' => $targetProfile];
        }

        if ($viewerGender === 'male' && $targetGender === 'female') {
            return ['bride' => $targetProfile, 'groom' => $viewerProfile];
        }

        return null;
    }

    // ---------- the eight kootas ----------

    private function varnaSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->varnaKey === null ? __('profile.gunamilan_missing_bride_varna') : null,
            $groom->varnaKey === null ? __('profile.gunamilan_missing_groom_varna') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            $brideRank = self::VARNA_RANKS[$bride->varnaKey] ?? null;
            $groomRank = self::VARNA_RANKS[$groom->varnaKey] ?? null;
            if ($brideRank === null || $groomRank === null) {
                $missing[] = __('profile.gunamilan_missing_bride_varna');
            } else {
                $points = $groomRank >= $brideRank ? 1.0 : 0.0;
                $note = $points > 0 ? __('profile.gunamilan_note_compatible') : __('profile.gunamilan_note_not_compatible');
            }
        }

        return $this->section('varna', __('profile.gunamilan_section_varna'), $points, 1.0, $bride->varnaLabel, $groom->varnaLabel, $note, $missing);
    }

    private function vashyaSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->vashyaKey === null ? __('profile.gunamilan_missing_bride_vashya') : null,
            $groom->vashyaKey === null ? __('profile.gunamilan_missing_groom_vashya') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            $value = self::VASHYA_POINTS[$bride->vashyaKey][$groom->vashyaKey] ?? null;
            if ($value === null) {
                $missing[] = __('profile.gunamilan_missing_bride_vashya');
            } else {
                $points = $value;
                $note = match (true) {
                    $bride->vashyaKey === $groom->vashyaKey => __('profile.gunamilan_note_same_group'),
                    $points >= 1.0 => __('profile.gunamilan_note_compatible'),
                    default => __('profile.gunamilan_note_partial'),
                };
            }
        }

        return $this->section('vashya', __('profile.gunamilan_section_vashya'), $points, 2.0, $bride->vashyaLabel, $groom->vashyaLabel, $note, $missing);
    }

    private function taraSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->nakshatraNumber === null ? __('profile.gunamilan_missing_bride_nakshatra') : null,
            $groom->nakshatraNumber === null ? __('profile.gunamilan_missing_groom_nakshatra') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            $forward = $this->rules->taraForNumbers($bride->nakshatraNumber, $groom->nakshatraNumber);
            $reverse = $this->rules->taraForNumbers($groom->nakshatraNumber, $bride->nakshatraNumber);
            $points = (float) $forward['points'] + (float) $reverse['points'];
            $note = __('profile.gunamilan_note_tara', [
                'bride' => $forward['tara_label'] ?? '-',
                'groom' => $reverse['tara_label'] ?? '-',
            ]);
        }

        return $this->section('tara', __('profile.gunamilan_section_tara'), $points, 3.0, $bride->nakshatraLabel, $groom->nakshatraLabel, $note, $missing);
    }

    private function yoniSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->yoniKey === null ? __('profile.gunamilan_missing_bride_yoni') : null,
            $groom->yoniKey === null ? __('profile.gunamilan_missing_groom_yoni') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            if ($bride->yoniKey === $groom->yoniKey) {
                $points = 4.0;
                $note = __('profile.gunamilan_note_same_group');
            } elseif (in_array($bride->yoniKey.':'.$groom->yoniKey, self::YONI_ENEMY_PAIRS, true)) {
                $points = 0.0;
                $note = __('profile.gunamilan_note_not_compatible');
            } else {
                $points = 2.0;
                $note = __('profile.gunamilan_note_partial');
            }
        }

        return $this->section('yoni', __('profile.gunamilan_section_yoni'), $points, 4.0, $bride->yoniLabel, $groom->yoniLabel, $note, $missing);
    }

    private function grahaMaitriSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->lordKey === null ? __('profile.gunamilan_missing_bride_lord') : null,
            $groom->lordKey === null ? __('profile.gunamilan_missing_groom_lord') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            $points = (float) $this->rules->grahaMaitriPointsForLords($bride->lordKey, $groom->lordKey);
            $note = __('profile.gunamilan_note_calculated');
        }

        return $this->section('graha_maitri', __('profile.gunamilan_section_graha_maitri'), $points, 5.0, $bride->lordLabel, $groom->lordLabel, $note, $missing);
    }

    private function ganaSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->ganKey === null ? __('profile.gunamilan_missing_bride_gan') : null,
            $groom->ganKey === null ? __('profile.gunamilan_missing_groom_gan') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            $value = self::GANA_POINTS[$bride->ganKey.':'.$groom->ganKey] ?? null;
            if ($value === null) {
                $missing[] = __('profile.gunamilan_missing_bride_gan');
            } else {
                $points = $value;
                $note = $points >= 5.0 ? __('profile.gunamilan_note_compatible') : __('profile.gunamilan_note_partial');
            }
        }

        return $this->section('gana', __('profile.gunamilan_section_gana'), $points, 6.0, $bride->ganLabel, $groom->ganLabel, $note, $missing);
    }

    private function bhakootSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->rashiPosition === null ? __('profile.gunamilan_missing_bride_rashi') : null,
            $groom->rashiPosition === null ? __('profile.gunamilan_missing_groom_rashi') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        $isDosha = null;
        if ($missing === []) {
            $result = $this->rules->bhakootForPositions($bride->rashiPosition, $groom->rashiPosition);
            $points = (float) $result['points'];
            $isDosha = (bool) $result['is_dosha'];
            $note = $isDosha ? __('profile.gunamilan_note_dosha') : __('profile.gunamilan_note_compatible');
        }

        return $this->section('bhakoot', __('profile.gunamilan_section_bhakoot'), $points, 7.0, $bride->rashiLabel, $groom->rashiLabel, $note, $missing, $isDosha);
    }

    private function nadiSection(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $missing = array_values(array_filter([
            $bride->nadiKey === null ? __('profile.gunamilan_missing_bride_nadi') : null,
            $groom->nadiKey === null ? __('profile.gunamilan_missing_groom_nadi') : null,
        ]));

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        $isDosha = null;
        if ($missing === []) {
            // Same Nadi on both sides IS the Nadi dosha; different Nadi scores full.
            $isDosha = $bride->nadiKey === $groom->nadiKey;
            $points = $isDosha ? 0.0 : 8.0;
            $note = $isDosha ? __('profile.gunamilan_note_dosha') : __('profile.gunamilan_note_compatible');
        }

        return $this->section('nadi', __('profile.gunamilan_section_nadi'), $points, 8.0, $bride->nadiLabel, $groom->nadiLabel, $note, $missing, $isDosha);
    }

    /**
     * @param  array<int, string>  $missing
     * @param  bool|null  $isDosha  null = not computable for this koota.
     * @return array<string, mixed>
     */
    private function section(
        string $key,
        string $label,
        float $points,
        float $maxPoints,
        ?string $brideValue,
        ?string $groomValue,
        string $note,
        array $missing,
        ?bool $isDosha = null,
    ): array {
        $points = round($points, 1);
        $missing = array_values(array_unique(array_filter($missing)));

        return [
            'key' => $key,
            'label' => $label,
            'points' => $points,
            'max_points' => $maxPoints,
            'computable' => $missing === [],
            'status' => $missing !== [] ? 'missing' : ($points >= $maxPoints ? 'full' : 'partial'),
            'bride_value' => $brideValue !== null && $brideValue !== '' ? $brideValue : '-',
            'groom_value' => $groomValue !== null && $groomValue !== '' ? $groomValue : '-',
            'note' => $note,
            'is_dosha' => $missing === [] ? $isDosha : null,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<int, array{side: string, label: string}>  $missing
     * @return array<int, array{side: string, label: string}>
     */
    private function uniqueMissing(array $missing): array
    {
        $seen = [];
        $out = [];
        foreach ($missing as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = ($item['side'] ?? '').'|'.$label;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'side' => (string) ($item['side'] ?? ''),
                'label' => $label,
            ];
        }

        return $out;
    }
}
