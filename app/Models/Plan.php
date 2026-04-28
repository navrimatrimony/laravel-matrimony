<?php

namespace App\Models;

use App\Exceptions\QuotaPolicySourceViolation;
use App\Services\FeatureUsageService;
use App\Services\PlanQuotaPolicyMirror;
use App\Services\PlanQuotaUiSource;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanFeatureLabel;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Plan extends Model
{
    /**
     * Public pricing (/plans): suppress these mirrored or legacy {@see PlanFeature} keys (UI projection only).
     *
     * @var list<string>
     */
    private const PRICING_CATALOG_UI_HIDDEN_KEYS = [
        PlanFeatureKeys::PHOTO_BLUR_LIMIT,
        PlanFeatureKeys::PHOTO_FULL_ACCESS,
        PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT,
        SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES,
        'chat_images',
        'whatsapp_button',
    ];

    protected $fillable = [
        'name',
        'name_mr',
        'description',
        'slug',
        'tier',
        'price',
        'discount_percent',
        'list_price_rupees',
        'gst_inclusive',
        'duration_days',
        'duration_quantity',
        'duration_unit',
        'default_billing_key',
        'grace_period_days',
        'leftover_quota_carry_window_days',
        'is_active',
        'is_visible',
        'sort_order',
        'highlight',
        'applies_to_gender',
        'marketing_badge',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_percent' => 'integer',
        'list_price_rupees' => 'decimal:2',
        'gst_inclusive' => 'boolean',
        'duration_days' => 'integer',
        'duration_quantity' => 'integer',
        'grace_period_days' => 'integer',
        'leftover_quota_carry_window_days' => 'integer',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'highlight' => 'boolean',
        'tier' => 'integer',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    /**
     * Multi-duration rows (parallel to legacy {@see terms()} / admin billing UI).
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)->orderBy('sort_order');
    }

    /**
     * Structured feature engine (legacy table; non-quota keys only for runtime reads).
     */
    public function featureConfigs(): HasMany
    {
        return $this->hasMany(PlanFeatureConfig::class);
    }

    /**
     * Phase 1 quota policies (admin SSOT). Runtime limits must read these rows (or checkout snapshot), not {@see features()}.
     */
    public function quotaPolicies(): HasMany
    {
        return $this->hasMany(PlanQuotaPolicy::class, 'plan_id');
    }

    /**
     * Cached {@see features()} rows (60s TTL). Invalidated when {@see PlanFeature} rows change.
     *
     * @return Collection<int, PlanFeature>
     */
    public function getCachedFeatures(): Collection
    {
        return Cache::rememberForever(
            "plan_features_{$this->id}",
            fn () => $this->features()->get()
        );
    }

    /**
     * Typed feature value wrapper over {@see getFeatureValue} using {@see config('plan_features')}.
     * Does not replace existing string-based APIs (backward compatible).
     */
    public function getTypedFeatureValue(string $key): mixed
    {
        $value = $this->getFeatureValue($key);
        if ($value === null) {
            return null;
        }

        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        $config = config('plan_features')[$normalized] ?? null;
        if (! $config || ! is_array($config)) {
            return $value;
        }

        return match ((string) ($config['type'] ?? '')) {
            'limit', 'days' => (int) $value,
            'boolean' => (bool) ((int) $value),
            default => $value,
        };
    }

    public function forgetCachedPlanFeatures(): void
    {
        static::forgetCachedPlanFeaturesByPlanId((int) $this->id);
    }

    public static function forgetCachedPlanFeaturesByPlanId(int $planId): void
    {
        Cache::forget("plan_features_{$planId}");
    }

    /**
     * Feature value for a key, with {@see FeatureUsageService} alias normalization.
     */
    public function getFeatureValue(string $key): ?string
    {
        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        $this->assertPlanFeaturesReadAllowed($normalized, 'Plan::getFeatureValue');
        $rows = $this->relationLoaded('features')
            ? $this->features
            : $this->getCachedFeatures();

        $row = $rows->firstWhere('key', $normalized);

        return $row?->value !== null ? (string) $row->value : null;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(PlanTerm::class)->orderBy('sort_order');
    }

    public function visibleTerms(): HasMany
    {
        return $this->terms()->where('is_visible', true);
    }

    public function planPrices(): HasMany
    {
        return $this->prices();
    }

    public function visiblePlanPrices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)
            ->where('is_visible', true)
            ->orderBy('sort_order');
    }

    /**
     * Final price after discount_percent (computed only; never stored in DB).
     */
    public function getFinalPriceAttribute(): float
    {
        $base = (float) $this->price;
        $d = (int) ($this->discount_percent ?? 0);
        if ($this->discount_percent && $d > 0) {
            $d = min(100, max(0, $d));

            return round($base * (1 - ($d / 100)), 2);
        }

        return round($base, 2);
    }

    public function hasActiveDiscount(): bool
    {
        return ((int) ($this->discount_percent ?? 0)) > 0;
    }

    public function featureValue(string $key, ?string $default = null): ?string
    {
        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        $this->assertPlanFeaturesReadAllowed($normalized, 'Plan::featureValue');

        $row = $this->relationLoaded('features')
            ? $this->features->firstWhere('key', $normalized)
            : $this->features()->where('key', $normalized)->first();

        if (! $row) {
            return $default;
        }

        return (string) $row->value;
    }

    private function assertPlanFeaturesReadAllowed(string $normalizedKey, string $context): void
    {
        if (PlanQuotaPolicyKeys::isForbiddenPlanFeatureRowKey($normalizedKey)) {
            throw QuotaPolicySourceViolation::planFeaturesReadForbidden($normalizedKey, $context);
        }
    }

    /**
     * True for legacy slug {@code free} or gendered free tiers ({@code free_male}, {@code free_female}).
     */
    public static function isFreeCatalogSlug(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        $s = strtolower(trim((string) $slug));

        return $s === 'free' || str_starts_with($s, 'free_');
    }

    /**
     * Public catalog / checkout: whether this plan row is intended for the member's profile gender.
     *
     * Product invariant (pinned): plans targeted at {@code male} or {@code female} are visible only when
     * {@code matrimonyProfile.gender.key} matches exactly. Guests, missing gender, and {@code other}
     * never see opposite-gender rows; only {@code all} / empty {@code applies_to_gender} applies there.
     * Do not widen this without explicit product sign-off.
     */
    public static function profileGenderAllowsPlan(?User $user, self $plan): bool
    {
        $target = strtolower(trim((string) ($plan->applies_to_gender ?? 'all')));
        if ($target === '' || $target === 'all') {
            return true;
        }

        if (! in_array($target, ['male', 'female'], true)) {
            return false;
        }

        if ($user === null) {
            return false;
        }

        $user->loadMissing('matrimonyProfile.gender');
        $viewerGenderKey = strtolower(trim((string) ($user->matrimonyProfile?->gender?->key ?? '')));
        if ($viewerGenderKey === '' || $viewerGenderKey === 'other') {
            return false;
        }

        return $target === $viewerGenderKey;
    }

    /**
     * Default free catalog row for entitlements when the member has no paid subscription.
     * Uses profile gender when available ({@code free_male} / {@code free_female}).
     */
    public static function defaultFree(?User $user = null): ?self
    {
        $genderKey = '';
        if ($user !== null) {
            $user->loadMissing('matrimonyProfile.gender');
            $genderKey = strtolower(trim((string) ($user->matrimonyProfile?->gender?->key ?? '')));
        }

        if ($genderKey === 'male') {
            $hit = static::query()->where('slug', 'free_male')->where('is_active', true)->first();
            if ($hit) {
                return $hit;
            }
        } elseif ($genderKey === 'female') {
            $hit = static::query()->where('slug', 'free_female')->where('is_active', true)->first();
            if ($hit) {
                return $hit;
            }
        }

        return static::query()
            ->whereIn('slug', ['free', 'free_male', 'free_female'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * Public pricing (/plans): quota engine rows are SSOT; {@see features()} fills only keys not emitted by quota mirror
     * (e.g. legacy chat image flag) so catalog numbers match admin {@see PlanQuotaPolicy}.
     *
     * Final projection: non-user-facing keys ({@see self::PRICING_CATALOG_UI_HIDDEN_KEYS}), disabled quotas, and
     * zero / empty limits are excluded here only — DB rows are unchanged.
     */
    public function catalogFeatureRowsForPricing(): \Illuminate\Support\Collection
    {
        $this->loadMissing(['quotaPolicies', 'features']);
        $mirroredKeys = [];
        $rows = collect();

        $payloads = PlanQuotaUiSource::policyPayloadsFromPlan($this);
        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            if (! isset($payloads[$fk]) || ! is_array($payloads[$fk])) {
                continue;
            }
            $payload = $payloads[$fk];
            if (! PlanFeatureLabel::quotaCatalogShouldListRow($fk, $payload)) {
                continue;
            }
            foreach (PlanQuotaPolicyMirror::mirroredFeatureRowsFromPolicyPayload($fk, $payload) as $pair) {
                if (! PlanFeatureLabel::quotaCatalogShouldListMirroredPair($pair['key'], $pair['value'], $fk)) {
                    continue;
                }
                if ($pair['key'] === FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS) {
                    continue;
                }
                $mirroredKeys[$pair['key']] = true;
                $rows->push((object) [
                    'key' => $pair['key'],
                    'value' => $pair['value'],
                    'catalog_quota_payload' => $payload,
                ]);
            }
        }

        foreach ($this->features as $f) {
            $k = (string) $f->key;
            if (PlanQuotaPolicyKeys::isForbiddenPlanFeatureRowKey($k)) {
                continue;
            }
            if ($k === PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD) {
                continue;
            }
            if (isset($mirroredKeys[$k])) {
                continue;
            }
            if (! PlanFeatureLabel::quotaCatalogShouldListMirroredPair($k, (string) $f->value, null)) {
                continue;
            }
            $rows->push($f);
        }

        return $rows
            ->filter(function (object $row): bool {
                $key = (string) $row->key;
                if (in_array($key, self::PRICING_CATALOG_UI_HIDDEN_KEYS, true)) {
                    return false;
                }
                if (property_exists($row, 'catalog_quota_payload') && is_array($row->catalog_quota_payload)) {
                    return PlanFeatureLabel::quotaCatalogShouldListRow($key, $row->catalog_quota_payload);
                }

                return PlanFeatureLabel::quotaCatalogShouldListMirroredPair($key, (string) $row->value, null);
            })
            ->values();
    }
}
