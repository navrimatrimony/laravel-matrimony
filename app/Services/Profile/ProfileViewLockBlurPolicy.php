<?php

namespace App\Services\Profile;

use App\Models\AdminSetting;

/**
 * Canonical reader of the admin setting {@see self::SETTING_KEY} ("Profile lock
 * experience → Blur strength"), and the one place that turns that 35–100 dial
 * into a display hint clients can render.
 *
 * Before this class the clamp was hand-copied into the admin form read, the
 * admin form write and the web profile controller, and the mobile API carried
 * no hint at all — so the member app hardcoded its own blur and the admin's
 * choice was silently ignored on mobile.
 *
 * The hint is emitted as a `blur_photo_class` string in the SAME grammar the
 * teaser payload already uses ({@see \App\Services\WhoViewed\WhoViewedTeaserPresenter}),
 * so the member app parses it with the helper it already has and no third
 * convention is invented. This class decides STRENGTH only; who may see which
 * photo stays with {@see \App\Services\ProfilePhotoAccessService}.
 */
class ProfileViewLockBlurPolicy
{
    public const SETTING_KEY = 'profile_view_lock_blur_strength';

    public const MIN_STRENGTH = 35;

    public const MAX_STRENGTH = 100;

    public const DEFAULT_STRENGTH = 78;

    /**
     * Fixed upscale token; the admin dial controls blur, not crop.
     */
    private const PHOTO_SCALE_PERCENT = 105;

    /**
     * Anchor points mapping admin strength → CSS blur radius in px.
     *
     * The middle anchor is the shipped default so a deployment that never
     * touched the slider keeps exactly the blur both clients render today
     * (40px === Tailwind `blur-2xl`, which the web album already uses and which
     * the member app's class→sigma curve resolves to sigma 18 — its current
     * hardcoded value). Endpoints land on `blur-md` and `blur-3xl`.
     */
    private const CSS_PX_AT_MIN = 12;

    private const CSS_PX_AT_DEFAULT = 40;

    private const CSS_PX_AT_MAX = 64;

    /**
     * Admin-configured strength, clamped to the range the admin form allows.
     */
    public function strength(): int
    {
        return self::clamp(AdminSetting::getValue(self::SETTING_KEY, (string) self::DEFAULT_STRENGTH));
    }

    /**
     * Clamp any raw value (stored string, request input) into the valid range.
     */
    public static function clamp(mixed $value): int
    {
        return max(self::MIN_STRENGTH, min(self::MAX_STRENGTH, (int) $value));
    }

    /**
     * CSS blur radius in px for a locked photo at the given (or current) strength.
     */
    public function photoBlurCssPx(?int $strength = null): int
    {
        $resolved = $strength === null ? $this->strength() : self::clamp($strength);

        if ($resolved <= self::DEFAULT_STRENGTH) {
            return (int) round(self::CSS_PX_AT_MIN + ($resolved - self::MIN_STRENGTH)
                * ((self::CSS_PX_AT_DEFAULT - self::CSS_PX_AT_MIN) / (self::DEFAULT_STRENGTH - self::MIN_STRENGTH)));
        }

        return (int) round(self::CSS_PX_AT_DEFAULT + ($resolved - self::DEFAULT_STRENGTH)
            * ((self::CSS_PX_AT_MAX - self::CSS_PX_AT_DEFAULT) / (self::MAX_STRENGTH - self::DEFAULT_STRENGTH)));
    }

    /**
     * Display hint for a photo whose per-photo `blur` flag is true.
     *
     * Same token grammar as the teaser `blur_photo_class`: an arbitrary blur
     * radius, an upscale and an opacity. `opacity-100` is explicit "do not dim"
     * — the dial only concealed detail, it never dimmed the album.
     */
    public function photoBlurClass(?int $strength = null): string
    {
        return 'blur-['.$this->photoBlurCssPx($strength).'px] scale-'.self::PHOTO_SCALE_PERCENT.' opacity-100';
    }
}
