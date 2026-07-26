<?php

namespace App\Services\Gunamilan;

/**
 * मंगळ (Mangal / Manglik) comparison — deliberately OUTSIDE the 36-guna score.
 *
 * Ashta-Koota does not contain Mangal. The 36 points are Varna, Vashya, Tara,
 * Yoni, Graha Maitri, Gana, Bhakoot and Nadi, and nothing else; folding a
 * Mangal penalty into that total would report a number no astrologer or family
 * could reconcile with a printed patrika. So Mangal is computed here as its own
 * small verdict, from the MANUALLY entered
 * `profile_horoscope_data.mangal_dosh_type_id` lookup (the only place this fact
 * is stored — it is not derivable from rashi or nakshatra), and surfaced
 * alongside the score so the UI can show the two separately.
 *
 * ## The rule (owner decision, 2026-07-26)
 *
 * | bride \ groom | non-manglik | manglik  | unknown        |
 * |---------------|-------------|----------|----------------|
 * | non-manglik   | compatible  | NOT      | not computable |
 * | manglik       | NOT         | compatible | not computable |
 * | unknown       | not comp.   | not comp.| not computable |
 *
 * Both-non-manglik and both-manglik are compatible; the traditional reading is
 * that one Mangal dosha cancels the other. Exactly one manglik side is the only
 * incompatible case.
 *
 * ## Unknown is never a rejection
 *
 * `don_t_know` is a real, frequently chosen option in the dropdown, `other` is
 * a sentinel, and the field is optional — so "unknown" is the NORMAL state, not
 * a defect. It returns {@see self::STATUS_NOT_COMPUTABLE} and callers must
 * treat that as "no signal": never a filter, never a score penalty, never
 * displayed as "incompatible".
 *
 * ## Weight
 *
 * {@see self::WEIGHT} is 0.05 — low, per the owner decision. A future matching
 * blend should read roughly
 * `(gunamilanPoints / 36) * (1 - WEIGHT) + mangalScore * WEIGHT`, and must skip
 * the Mangal term entirely (renormalising) when the status is not computable.
 */
final class MangalCompatibility
{
    public const STATUS_COMPATIBLE = 'compatible';

    public const STATUS_NOT_COMPATIBLE = 'not_compatible';

    public const STATUS_NOT_COMPUTABLE = 'not_computable';

    /** Low weight relative to the 36-guna score. Mangal informs; it does not decide. */
    public const WEIGHT = 0.05;

    /** `master_mangal_dosh_types` keys that mean the person IS manglik. */
    public const MANGLIK_KEYS = ['bhumangal', 'chovamangal', 'antya_mangal', 'anshik_mangal'];

    /** Keys that mean the person is NOT manglik. */
    public const NON_MANGLIK_KEYS = ['none'];

    /**
     * Keys that carry no answer. `don_t_know` is an explicit dropdown choice;
     * `other` is the generic sentinel. Both mean "we do not know", not "no".
     */
    public const UNKNOWN_KEYS = ['don_t_know', 'dont_know', 'unknown', 'other'];

    /**
     * @return array{
     *     status: string,
     *     computable: bool,
     *     is_compatible: bool|null,
     *     bride_key: string|null,
     *     groom_key: string|null,
     *     bride_label: string|null,
     *     groom_label: string|null,
     *     bride_is_manglik: bool|null,
     *     groom_is_manglik: bool|null,
     *     score: float|null,
     *     weight: float,
     *     label: string,
     *     note: string
     * }
     */
    public function compare(GunamilanKootaKey $bride, GunamilanKootaKey $groom): array
    {
        $brideManglik = self::isManglik($bride->mangalKey);
        $groomManglik = self::isManglik($groom->mangalKey);

        $status = match (true) {
            $brideManglik === null || $groomManglik === null => self::STATUS_NOT_COMPUTABLE,
            $brideManglik === $groomManglik => self::STATUS_COMPATIBLE,
            default => self::STATUS_NOT_COMPATIBLE,
        };

        $computable = $status !== self::STATUS_NOT_COMPUTABLE;

        return [
            'status' => $status,
            'computable' => $computable,
            'is_compatible' => $computable ? $status === self::STATUS_COMPATIBLE : null,
            'bride_key' => $bride->mangalKey,
            'groom_key' => $groom->mangalKey,
            'bride_label' => $bride->mangalLabel,
            'groom_label' => $groom->mangalLabel,
            'bride_is_manglik' => $brideManglik,
            'groom_is_manglik' => $groomManglik,
            'score' => $computable ? ($status === self::STATUS_COMPATIBLE ? 1.0 : 0.0) : null,
            'weight' => self::WEIGHT,
            'label' => __('profile.gunamilan_mangal_dosha'),
            'note' => match ($status) {
                self::STATUS_COMPATIBLE => __('profile.gunamilan_mangal_note_compatible'),
                self::STATUS_NOT_COMPATIBLE => __('profile.gunamilan_mangal_note_not_compatible'),
                default => __('profile.gunamilan_mangal_note_unknown'),
            },
        ];
    }

    /**
     * true = manglik, false = explicitly not manglik, null = unknown / not filled.
     * Null is the answer for a missing row, a blank column, `don_t_know`,
     * `other`, and any key this class does not recognise — an unrecognised key
     * must never be silently read as "no".
     */
    public static function isManglik(?string $mangalKey): ?bool
    {
        $key = strtolower(trim((string) $mangalKey));

        if ($key === '' || in_array($key, self::UNKNOWN_KEYS, true)) {
            return null;
        }
        if (in_array($key, self::MANGLIK_KEYS, true)) {
            return true;
        }
        if (in_array($key, self::NON_MANGLIK_KEYS, true)) {
            return false;
        }

        return null;
    }
}
