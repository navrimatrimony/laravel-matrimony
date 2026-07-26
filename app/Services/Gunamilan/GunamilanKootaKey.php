<?php

namespace App\Services\Gunamilan;

use App\Models\MatrimonyProfile;
use App\Models\ProfileHoroscopeData;

/**
 * One profile's Gunamilan inputs, flattened and pre-resolved.
 *
 * Everything Ashta-Koota needs about a person reduces to ten scalars. Build
 * this ONCE per profile per run ({@see GunamilanService::kootaKeyFor()}), then
 * every pair comparison against it is pure array math with no database access
 * — which is what makes the engine usable inside the matching feed.
 *
 * Null on a property means "this input is not available", never "zero". The
 * `other` master rows (`master_gans`, `master_nadis`, `master_yonis`,
 * `master_rashis` id 14, `master_nakshatras` id 29) collapse to null here for
 * exactly that reason — see {@see GunamilanMasterData::isComputableKey()}.
 */
final class GunamilanKootaKey
{
    private function __construct(
        public readonly ?int $profileId,
        public readonly ?string $genderKey,
        public readonly ?int $rashiPosition,
        public readonly ?string $rashiLabel,
        public readonly ?int $nakshatraNumber,
        public readonly ?string $nakshatraLabel,
        public readonly ?string $varnaKey,
        public readonly ?string $varnaLabel,
        public readonly ?string $vashyaKey,
        public readonly ?string $vashyaLabel,
        public readonly ?string $lordKey,
        public readonly ?string $lordLabel,
        public readonly ?string $ganKey,
        public readonly ?string $ganLabel,
        public readonly ?string $nadiKey,
        public readonly ?string $nadiLabel,
        public readonly ?string $yoniKey,
        public readonly ?string $yoniLabel,
        public readonly ?string $mangalKey,
        public readonly ?string $mangalLabel,
        public readonly bool $hasHoroscopeRow,
    ) {
    }

    /**
     * Empty key for a profile with no `profile_horoscope_data` row at all.
     * Distinct from a row full of NULLs only by {@see $hasHoroscopeRow}.
     */
    public static function absent(?int $profileId = null, ?string $genderKey = null): self
    {
        return new self(
            profileId: $profileId,
            genderKey: $genderKey,
            rashiPosition: null,
            rashiLabel: null,
            nakshatraNumber: null,
            nakshatraLabel: null,
            varnaKey: null,
            varnaLabel: null,
            vashyaKey: null,
            vashyaLabel: null,
            lordKey: null,
            lordLabel: null,
            ganKey: null,
            ganLabel: null,
            nadiKey: null,
            nadiLabel: null,
            yoniKey: null,
            yoniLabel: null,
            mangalKey: null,
            mangalLabel: null,
            hasHoroscopeRow: false,
        );
    }

    /**
     * Flatten a saved horoscope row. Gan / Nadi / Yoni fall back to the
     * nakshatra-derived value (`master_nakshatra_attributes`) when the profile
     * left the column blank — the same autofill rule the wizard applies, so a
     * blank column is never a scoring penalty.
     */
    public static function fromHoroscope(?ProfileHoroscopeData $horoscope, GunamilanMasterData $masters, ?string $genderKey = null): self
    {
        if ($horoscope === null) {
            return self::absent(null, $genderKey);
        }

        $rashiId = $horoscope->rashi_id !== null ? (int) $horoscope->rashi_id : null;
        $nakshatraId = $horoscope->nakshatra_id !== null ? (int) $horoscope->nakshatra_id : null;

        $rashi = $masters->rashi($rashiId);
        if ($rashi !== null && ! GunamilanMasterData::isComputableKey($rashi['key'])) {
            $rashi = null;
        }

        $nakshatra = $masters->nakshatra($nakshatraId);
        if ($nakshatra !== null && ! GunamilanMasterData::isComputableKey($nakshatra['key'])) {
            $nakshatra = null;
        }

        $attributes = $nakshatra !== null ? $masters->nakshatraAttributes((int) $nakshatra['id']) : null;

        $varna = $rashi !== null ? $masters->varna($rashi['varna_id']) : null;
        $vashya = $rashi !== null ? $masters->vashya($rashi['vashya_id']) : null;
        $lord = $rashi !== null ? $masters->rashiLord($rashi['rashi_lord_id']) : null;

        $gan = self::resolveRow(
            $masters->gan($horoscope->gan_id !== null ? (int) $horoscope->gan_id : null),
            fn () => $masters->gan($attributes['gan_id'] ?? null),
        );
        $nadi = self::resolveRow(
            $masters->nadi($horoscope->nadi_id !== null ? (int) $horoscope->nadi_id : null),
            fn () => $masters->nadi($attributes['nadi_id'] ?? null),
        );

        $yoniKey = $masters->canonicalYoniKey($horoscope->yoni_id !== null ? (int) $horoscope->yoni_id : null)
            ?? $masters->canonicalYoniKey($attributes['yoni_id'] ?? null);
        $yoniRow = $masters->yoniRowForCanonicalKey($yoniKey);

        $mangal = $masters->mangalDoshType($horoscope->mangal_dosh_type_id !== null ? (int) $horoscope->mangal_dosh_type_id : null);

        return new self(
            profileId: $horoscope->profile_id !== null ? (int) $horoscope->profile_id : null,
            genderKey: $genderKey,
            rashiPosition: $rashi['position'] ?? null,
            rashiLabel: self::labelOrNull($rashi),
            nakshatraNumber: $nakshatra['number'] ?? null,
            nakshatraLabel: self::labelOrNull($nakshatra),
            varnaKey: self::computableKey($varna),
            varnaLabel: self::labelOrNull($varna),
            vashyaKey: self::computableKey($vashya),
            vashyaLabel: self::labelOrNull($vashya),
            lordKey: self::computableKey($lord),
            lordLabel: self::labelOrNull($lord),
            ganKey: self::computableKey($gan),
            ganLabel: self::labelOrNull($gan),
            nadiKey: self::computableKey($nadi),
            nadiLabel: self::labelOrNull($nadi),
            yoniKey: $yoniKey,
            yoniLabel: $yoniRow !== null ? GunamilanMasterData::label($yoniRow) : null,
            // Mangal keeps its raw key including `don_t_know` / `other`; the
            // comparison itself decides what "unknown" means (never a rejection).
            mangalKey: $mangal !== null ? (string) $mangal['key'] : null,
            mangalLabel: self::labelOrNull($mangal),
            hasHoroscopeRow: true,
        );
    }

    /** Convenience: flatten a profile that already has `horoscope` + `gender` loaded. */
    public static function fromProfile(MatrimonyProfile $profile, GunamilanMasterData $masters): self
    {
        $genderKey = strtolower((string) ($profile->gender?->key ?? '')) ?: null;
        $horoscope = $profile->horoscope;

        if ($horoscope === null) {
            return self::absent((int) $profile->id, $genderKey);
        }

        $key = self::fromHoroscope($horoscope, $masters, $genderKey);

        return $key->profileId === null ? $key->withProfileId((int) $profile->id) : $key;
    }

    public function withProfileId(int $profileId): self
    {
        return new self(
            profileId: $profileId,
            genderKey: $this->genderKey,
            rashiPosition: $this->rashiPosition,
            rashiLabel: $this->rashiLabel,
            nakshatraNumber: $this->nakshatraNumber,
            nakshatraLabel: $this->nakshatraLabel,
            varnaKey: $this->varnaKey,
            varnaLabel: $this->varnaLabel,
            vashyaKey: $this->vashyaKey,
            vashyaLabel: $this->vashyaLabel,
            lordKey: $this->lordKey,
            lordLabel: $this->lordLabel,
            ganKey: $this->ganKey,
            ganLabel: $this->ganLabel,
            nadiKey: $this->nadiKey,
            nadiLabel: $this->nadiLabel,
            yoniKey: $this->yoniKey,
            yoniLabel: $this->yoniLabel,
            mangalKey: $this->mangalKey,
            mangalLabel: $this->mangalLabel,
            hasHoroscopeRow: $this->hasHoroscopeRow,
        );
    }

    /**
     * Every input the 36-guna score needs is present. A pair where BOTH sides
     * answer true is the only case that produces a real score.
     */
    public function hasCompleteKootaInputs(): bool
    {
        return $this->rashiPosition !== null
            && $this->nakshatraNumber !== null
            && $this->varnaKey !== null
            && $this->vashyaKey !== null
            && $this->lordKey !== null
            && $this->ganKey !== null
            && $this->nadiKey !== null
            && $this->yoniKey !== null;
    }

    /**
     * @param  array<string, mixed>|null  $primary
     * @param  callable():(array<string, mixed>|null)  $fallback
     * @return array<string, mixed>|null
     */
    private static function resolveRow(?array $primary, callable $fallback): ?array
    {
        if ($primary !== null && GunamilanMasterData::isComputableKey($primary['key'] ?? null)) {
            return $primary;
        }

        return $fallback();
    }

    /** @param  array<string, mixed>|null  $row */
    private static function computableKey(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }

        $key = (string) $row['key'];

        return GunamilanMasterData::isComputableKey($key) ? $key : null;
    }

    /** @param  array<string, mixed>|null  $row */
    private static function labelOrNull(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }

        $label = GunamilanMasterData::label($row);

        return $label !== '' ? $label : null;
    }
}
