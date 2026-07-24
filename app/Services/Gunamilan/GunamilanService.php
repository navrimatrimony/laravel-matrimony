<?php

namespace App\Services\Gunamilan;

use App\Models\MatrimonyProfile;
use App\Models\ProfileHoroscopeData;
use App\Services\HoroscopeRuleService;
use App\Support\LocalizedText;

class GunamilanService
{
    private const MAX_POINTS = 36.0;

    private const VARNA_RANKS = [
        'shudra' => 1,
        'vaishya' => 2,
        'kshatriya' => 3,
        'brahmin' => 4,
    ];

    private const VASHYA_COMPATIBLE_PAIRS = [
        'manav:chatushpada' => 1.0,
        'chatushpada:manav' => 1.0,
        'manav:jalachar' => 1.0,
        'jalachar:manav' => 1.0,
        'chatushpada:jalachar' => 1.0,
        'jalachar:chatushpada' => 1.0,
        'vanchar:chatushpada' => 1.0,
        'chatushpada:vanchar' => 1.0,
    ];

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

    private const YONI_ENEMY_PAIRS = [
        'horse:buffalo',
        'buffalo:horse',
        'elephant:lion',
        'lion:elephant',
        'sheep:monkey',
        'monkey:sheep',
        'serpent:mongoose',
        'mongoose:serpent',
        'dog:deer',
        'deer:dog',
        'cat:rat',
        'rat:cat',
        'cow:tiger',
        'tiger:cow',
    ];

    public function __construct(private readonly HoroscopeRuleService $rules)
    {
    }

    /**
     * Calculate a read-only Ashta-Koota result from saved horoscope fields.
     *
     * @return array{
     *     available: bool,
     *     total_points: float,
     *     max_points: float,
     *     sections: array<int, array<string, mixed>>,
     *     missing_fields: array<int, array{side: string, label: string}>,
     *     bride_profile_id: int|null,
     *     groom_profile_id: int|null
     * }
     */
    public function calculate(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile): array
    {
        $viewerProfile->loadMissing([
            'gender',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
        ]);
        $targetProfile->loadMissing([
            'gender',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.yoni',
        ]);

        $pair = $this->resolveBrideGroom($viewerProfile, $targetProfile);
        $missing = [];

        if ($pair === null) {
            $missing[] = [
                'side' => 'pair',
                'label' => __('profile.gunamilan_missing_bride_direction'),
            ];
        }

        $bride = $pair['bride'] ?? $viewerProfile;
        $groom = $pair['groom'] ?? $targetProfile;
        $brideHoroscope = $pair ? $bride->horoscope : null;
        $groomHoroscope = $pair ? $groom->horoscope : null;

        if (! $brideHoroscope) {
            $missing[] = [
                'side' => 'bride',
                'label' => __('profile.gunamilan_missing_bride_horoscope'),
            ];
        }
        if (! $groomHoroscope) {
            $missing[] = [
                'side' => 'groom',
                'label' => __('profile.gunamilan_missing_groom_horoscope'),
            ];
        }

        $sections = [
            $this->varnaSection($brideHoroscope, $groomHoroscope),
            $this->vashyaSection($brideHoroscope, $groomHoroscope),
            $this->taraSection($brideHoroscope, $groomHoroscope),
            $this->yoniSection($brideHoroscope, $groomHoroscope),
            $this->grahaMaitriSection($brideHoroscope, $groomHoroscope),
            $this->ganaSection($brideHoroscope, $groomHoroscope),
            $this->bhakootSection($brideHoroscope, $groomHoroscope),
            $this->nadiSection($brideHoroscope, $groomHoroscope),
        ];

        foreach ($sections as $section) {
            foreach (($section['missing'] ?? []) as $label) {
                $missing[] = [
                    'side' => $section['key'],
                    'label' => $label,
                ];
            }
        }

        $total = array_reduce(
            $sections,
            fn (float $carry, array $section): float => $carry + (float) $section['points'],
            0.0
        );

        return [
            'available' => $pair !== null && $brideHoroscope !== null && $groomHoroscope !== null,
            'total_points' => round($total, 1),
            'max_points' => self::MAX_POINTS,
            'sections' => $sections,
            'missing_fields' => $this->uniqueMissing($missing),
            'bride_profile_id' => $pair ? (int) $bride->id : null,
            'groom_profile_id' => $pair ? (int) $groom->id : null,
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

    private function varnaSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        $missing = $this->missingForRashi($bride, $groom);
        $brideVarna = $this->rules->getVarnaByRashi($bride?->rashi_id);
        $groomVarna = $this->rules->getVarnaByRashi($groom?->rashi_id);

        if ($brideVarna === null) {
            $missing[] = __('profile.gunamilan_missing_bride_varna');
        }
        if ($groomVarna === null) {
            $missing[] = __('profile.gunamilan_missing_groom_varna');
        }

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            $brideRank = self::VARNA_RANKS[$brideVarna->key] ?? null;
            $groomRank = self::VARNA_RANKS[$groomVarna->key] ?? null;
            if ($brideRank !== null && $groomRank !== null) {
                $points = $groomRank >= $brideRank ? 1.0 : 0.0;
                $note = $points > 0 ? __('profile.gunamilan_note_compatible') : __('profile.gunamilan_note_not_compatible');
            }
        }

        return $this->section(
            'varna',
            __('profile.gunamilan_section_varna'),
            $points,
            1.0,
            $this->valueLabel($brideVarna),
            $this->valueLabel($groomVarna),
            $note,
            $missing
        );
    }

    private function vashyaSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        $missing = $this->missingForRashi($bride, $groom);
        $brideVashya = $this->rules->getVashyaByRashi($bride?->rashi_id);
        $groomVashya = $this->rules->getVashyaByRashi($groom?->rashi_id);

        if ($brideVashya === null) {
            $missing[] = __('profile.gunamilan_missing_bride_vashya');
        }
        if ($groomVashya === null) {
            $missing[] = __('profile.gunamilan_missing_groom_vashya');
        }

        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');
        if ($missing === []) {
            if ($brideVashya->key === $groomVashya->key) {
                $points = 2.0;
                $note = __('profile.gunamilan_note_same_group');
            } else {
                $pair = $brideVashya->key.':'.$groomVashya->key;
                $points = self::VASHYA_COMPATIBLE_PAIRS[$pair] ?? 0.5;
                $note = $points >= 1.0 ? __('profile.gunamilan_note_compatible') : __('profile.gunamilan_note_partial');
            }
        }

        return $this->section(
            'vashya',
            __('profile.gunamilan_section_vashya'),
            $points,
            2.0,
            $this->valueLabel($brideVashya),
            $this->valueLabel($groomVashya),
            $note,
            $missing
        );
    }

    private function taraSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        $missing = $this->missingForNakshatra($bride, $groom);
        $forward = $this->rules->calculateTara($bride?->nakshatra_id, $groom?->nakshatra_id);
        $reverse = $this->rules->calculateTara($groom?->nakshatra_id, $bride?->nakshatra_id);
        $points = $missing === [] ? (float) $forward['points'] + (float) $reverse['points'] : 0.0;

        return $this->section(
            'tara',
            __('profile.gunamilan_section_tara'),
            $points,
            3.0,
            $this->valueLabel($bride?->nakshatra),
            $this->valueLabel($groom?->nakshatra),
            $missing === []
                ? __('profile.gunamilan_note_tara', [
                    'bride' => $forward['tara_label'] ?? '-',
                    'groom' => $reverse['tara_label'] ?? '-',
                ])
                : __('profile.gunamilan_note_missing'),
            $missing
        );
    }

    private function yoniSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        [$brideYoni, $brideMissing] = $this->resolveYoni($bride, __('profile.gunamilan_missing_bride_yoni'));
        [$groomYoni, $groomMissing] = $this->resolveYoni($groom, __('profile.gunamilan_missing_groom_yoni'));
        $missing = array_filter([$brideMissing, $groomMissing]);
        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');

        if ($missing === []) {
            if ($brideYoni->key === $groomYoni->key) {
                $points = 4.0;
                $note = __('profile.gunamilan_note_same_group');
            } elseif (in_array($brideYoni->key.':'.$groomYoni->key, self::YONI_ENEMY_PAIRS, true)) {
                $points = 0.0;
                $note = __('profile.gunamilan_note_not_compatible');
            } else {
                $points = 2.0;
                $note = __('profile.gunamilan_note_partial');
            }
        }

        return $this->section(
            'yoni',
            __('profile.gunamilan_section_yoni'),
            $points,
            4.0,
            $this->valueLabel($brideYoni),
            $this->valueLabel($groomYoni),
            $note,
            $missing
        );
    }

    private function grahaMaitriSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        $missing = $this->missingForRashi($bride, $groom);
        $result = $this->rules->calculateGrahaMaitri($bride?->rashi_id, $groom?->rashi_id);
        $brideLord = $this->rules->getRashiLord($bride?->rashi_id);
        $groomLord = $this->rules->getRashiLord($groom?->rashi_id);

        if ($brideLord === null) {
            $missing[] = __('profile.gunamilan_missing_bride_lord');
        }
        if ($groomLord === null) {
            $missing[] = __('profile.gunamilan_missing_groom_lord');
        }

        return $this->section(
            'graha_maitri',
            __('profile.gunamilan_section_graha_maitri'),
            $missing === [] ? (float) $result['points'] : 0.0,
            5.0,
            $this->valueLabel($brideLord),
            $this->valueLabel($groomLord),
            $missing === [] ? __('profile.gunamilan_note_calculated') : __('profile.gunamilan_note_missing'),
            $missing
        );
    }

    private function ganaSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        [$brideGan, $brideMissing] = $this->resolveGan($bride, __('profile.gunamilan_missing_bride_gan'));
        [$groomGan, $groomMissing] = $this->resolveGan($groom, __('profile.gunamilan_missing_groom_gan'));
        $missing = array_filter([$brideMissing, $groomMissing]);
        $points = 0.0;

        if ($missing === []) {
            $points = self::GANA_POINTS[$brideGan->key.':'.$groomGan->key] ?? 0.0;
        }

        return $this->section(
            'gana',
            __('profile.gunamilan_section_gana'),
            $points,
            6.0,
            $this->valueLabel($brideGan),
            $this->valueLabel($groomGan),
            $missing === []
                ? ($points >= 5.0 ? __('profile.gunamilan_note_compatible') : __('profile.gunamilan_note_partial'))
                : __('profile.gunamilan_note_missing'),
            $missing
        );
    }

    private function bhakootSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        $missing = $this->missingForRashi($bride, $groom);
        $result = $this->rules->calculateBhakoot($bride?->rashi_id, $groom?->rashi_id);

        return $this->section(
            'bhakoot',
            __('profile.gunamilan_section_bhakoot'),
            $missing === [] ? (float) $result['points'] : 0.0,
            7.0,
            $this->valueLabel($bride?->rashi),
            $this->valueLabel($groom?->rashi),
            $missing === []
                ? (($result['is_dosha'] ?? true) ? __('profile.gunamilan_note_dosha') : __('profile.gunamilan_note_compatible'))
                : __('profile.gunamilan_note_missing'),
            $missing
        );
    }

    private function nadiSection(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        [$brideNadi, $brideMissing] = $this->resolveNadi($bride, __('profile.gunamilan_missing_bride_nadi'));
        [$groomNadi, $groomMissing] = $this->resolveNadi($groom, __('profile.gunamilan_missing_groom_nadi'));
        $missing = array_filter([$brideMissing, $groomMissing]);
        $points = 0.0;
        $note = __('profile.gunamilan_note_missing');

        if ($missing === []) {
            $points = $brideNadi->key !== $groomNadi->key ? 8.0 : 0.0;
            $note = $points > 0 ? __('profile.gunamilan_note_compatible') : __('profile.gunamilan_note_dosha');
        }

        return $this->section(
            'nadi',
            __('profile.gunamilan_section_nadi'),
            $points,
            8.0,
            $this->valueLabel($brideNadi),
            $this->valueLabel($groomNadi),
            $note,
            $missing
        );
    }

    private function resolveGan(?ProfileHoroscopeData $horoscope, string $missingLabel): array
    {
        if ($horoscope?->gan) {
            return [$horoscope->gan, null];
        }

        $attrs = $this->rules->findNakshatraAttributes($horoscope?->nakshatra_id);
        $attrs?->loadMissing('gan');

        return $attrs?->gan ? [$attrs->gan, null] : [null, $missingLabel];
    }

    private function resolveNadi(?ProfileHoroscopeData $horoscope, string $missingLabel): array
    {
        if ($horoscope?->nadi) {
            return [$horoscope->nadi, null];
        }

        $attrs = $this->rules->findNakshatraAttributes($horoscope?->nakshatra_id);
        $attrs?->loadMissing('nadi');

        return $attrs?->nadi ? [$attrs->nadi, null] : [null, $missingLabel];
    }

    private function resolveYoni(?ProfileHoroscopeData $horoscope, string $missingLabel): array
    {
        if ($horoscope?->yoni) {
            return [$horoscope->yoni, null];
        }

        $attrs = $this->rules->findNakshatraAttributes($horoscope?->nakshatra_id);
        $attrs?->loadMissing('yoni');

        return $attrs?->yoni ? [$attrs->yoni, null] : [null, $missingLabel];
    }

    private function missingForRashi(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        return array_values(array_filter([
            $bride?->rashi_id ? null : __('profile.gunamilan_missing_bride_rashi'),
            $groom?->rashi_id ? null : __('profile.gunamilan_missing_groom_rashi'),
        ]));
    }

    private function missingForNakshatra(?ProfileHoroscopeData $bride, ?ProfileHoroscopeData $groom): array
    {
        return array_values(array_filter([
            $bride?->nakshatra_id ? null : __('profile.gunamilan_missing_bride_nakshatra'),
            $groom?->nakshatra_id ? null : __('profile.gunamilan_missing_groom_nakshatra'),
        ]));
    }

    private function section(
        string $key,
        string $label,
        float $points,
        float $maxPoints,
        string $brideValue,
        string $groomValue,
        string $note,
        array $missing
    ): array {
        $points = round($points, 1);

        return [
            'key' => $key,
            'label' => $label,
            'points' => $points,
            'max_points' => $maxPoints,
            'status' => $missing !== [] ? 'missing' : ($points >= $maxPoints ? 'full' : 'partial'),
            'bride_value' => $brideValue,
            'groom_value' => $groomValue,
            'note' => $note,
            'missing' => array_values($missing),
        ];
    }

    private function valueLabel(?object $value): string
    {
        return LocalizedText::column($value, 'label', ['label', 'name', 'key']) ?: '-';
    }

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
