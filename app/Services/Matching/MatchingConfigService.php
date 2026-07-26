<?php

namespace App\Services\Matching;

use App\Models\MatchingBehaviorWeight;
use App\Models\MatchingBoostRule;
use App\Models\MatchingEngineConfig;
use App\Models\MatchingField;
use App\Models\MatchingHardFilter;
use App\Support\SchemaPresence;
use Illuminate\Support\Facades\Cache;

/**
 * DB-backed matching engine configuration with cache and safe fallbacks when tables are empty.
 */
class MatchingConfigService
{
    public const CACHE_KEY = 'matching_engine_config_snapshot_v1';

    /**
     * गुणमिलन weight — deliberately ADDITIVE on top of the nine weights that already sum to 100,
     * not carved out of them.
     *
     * {@see MatchingService::calculateScore()} caps the total at 100, so the nine original fields
     * plus this one sum to 108 and the cap can bind. That is the accepted trade, and the alternative
     * was rejected on the owner's own rule:
     *
     *  - REBALANCING (shaving 8 points off the nine to keep the sum at 100) would cap every pair with
     *    no patrika data at 92 instead of 100. Only ~13% of profiles carry nakshatra + rashi, so 87%
     *    of the corpus would be silently marked down for a fact they were never asked to provide —
     *    exactly the "no data must never push anyone down" rule this layer exists to honour.
     *  - ADDITIVE keeps a data-less pair's score byte-identical to what it scores today (the
     *    गुणमिलन part contributes 0 with no penalty and nothing else shrank), while a pair with a
     *    real, computed 36-guna result can earn up to 8 more. The cap only binds for pairs already
     *    scoring 92+, i.e. rows already at the top of the feed where the ordering barely moves.
     *
     * 8 is the same tier as `height`, below `community` (16) and `age` (17): a real signal that never
     * outranks who the person actually is.
     *
     * Consequence to know about: the admin field-weight gauge now reads 108 against its "/ 100"
     * legend. That gauge is advisory only — nothing validates or normalises against it.
     */
    public const GUNAMILAN_WEIGHT = 8;

    private static bool $defaultsEnsured = false;

    /**
     * Per-process memo. Every weight/filter lookup calls this, and the scorer asks for nine weights per
     * candidate pair — so an uncached `SchemaPresence::hasTable()` here meant hundreds of `information_schema`
     * round trips per match request. Table presence cannot change inside a process.
     */
    private static ?bool $tablesExist = null;

    /**
     * Per-instance memo in front of the shared cache. The scorer asks for nine weights plus a field
     * enable check per candidate pair, and each of those was a `Cache::get` — a real `cache` table
     * SELECT on the production store. The values cannot change inside one request; {@see forgetCache()}
     * still drops them so an admin write is picked up immediately.
     *
     * @var array<string, array{label: string, type: string, category: string, is_active: bool, weight: int, max_weight: int}>|null
     */
    private ?array $activeFieldsMemo = null;

    /** @var array<string, mixed> */
    private array $runtimeValueMemo = [];

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->activeFieldsMemo = null;
        $this->runtimeValueMemo = [];
    }

    public function tablesExist(): bool
    {
        return self::$tablesExist ??= SchemaPresence::hasTable('matching_fields');
    }

    public function ensureDefaults(): void
    {
        if (! $this->tablesExist() || self::$defaultsEnsured) {
            return;
        }

        if (MatchingField::query()->exists()) {
            self::$defaultsEnsured = true;

            return;
        }

        $defaults = [
            ['field_key' => 'age', 'label' => 'Age alignment', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 17, 'max_weight' => 40],
            ['field_key' => 'location', 'label' => 'Location proximity', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 12, 'max_weight' => 30],
            ['field_key' => 'education', 'label' => 'Education level', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 12, 'max_weight' => 30],
            ['field_key' => 'occupation', 'label' => 'Occupation / sector', 'type' => 'similarity', 'category' => 'secondary', 'is_active' => true, 'weight' => 8, 'max_weight' => 25],
            ['field_key' => 'community', 'label' => 'Community (religion / caste)', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 16, 'max_weight' => 40],
            ['field_key' => 'preferences', 'label' => 'Partner preference fit (aggregate)', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 12, 'max_weight' => 40],
            ['field_key' => 'marital_status', 'label' => 'Marital status fit', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 9, 'max_weight' => 25],
            ['field_key' => 'height', 'label' => 'Height fit', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 8, 'max_weight' => 25],
            ['field_key' => 'diet', 'label' => 'Diet fit', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 6, 'max_weight' => 25],
            ['field_key' => 'gunamilan', 'label' => 'Gunamilan (36 guna + Mangal)', 'type' => 'similarity', 'category' => 'secondary', 'is_active' => true, 'weight' => self::GUNAMILAN_WEIGHT, 'max_weight' => 25],
        ];
        foreach ($defaults as $row) {
            MatchingField::query()->create($row);
        }

        $filters = [
            ['filter_key' => 'religion', 'mode' => config('matching.strict_religion_filter', false) ? MatchingHardFilter::MODE_STRICT : MatchingHardFilter::MODE_OFF, 'preferred_penalty_points' => 12],
            ['filter_key' => 'marital_status', 'mode' => config('matching.strict_marital_filter', false) ? MatchingHardFilter::MODE_STRICT : MatchingHardFilter::MODE_OFF, 'preferred_penalty_points' => 15],
            ['filter_key' => 'caste', 'mode' => MatchingHardFilter::MODE_OFF, 'preferred_penalty_points' => 12],
        ];
        foreach ($filters as $f) {
            MatchingHardFilter::query()->create($f);
        }

        $behaviors = [
            ['action' => 'view', 'weight' => 2, 'decay_days' => 30, 'is_active' => true],
            ['action' => 'like', 'weight' => 8, 'decay_days' => 90, 'is_active' => true],
            ['action' => 'skip', 'weight' => -6, 'decay_days' => 14, 'is_active' => true],
            ['action' => 'chat', 'weight' => 6, 'decay_days' => 60, 'is_active' => true],
        ];
        foreach ($behaviors as $b) {
            MatchingBehaviorWeight::query()->create($b);
        }

        $boost = MatchBoostSettingDefaults::snapshot();
        foreach ($boost as $rule) {
            MatchingBoostRule::query()->create($rule);
        }

        MatchingEngineConfig::query()->create([
            'config_key' => 'runtime',
            'config_value' => [
                'candidate_pool_limit' => null,
                'persist_cache' => null,
                'behavior_max_points' => 15,
            ],
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
        ]);

        self::$defaultsEnsured = true;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{label: string, type: string, category: string, is_active: bool, weight: int, max_weight: int}>
     */
    public function getActiveFields(): array
    {
        if (! $this->tablesExist()) {
            return $this->legacyFields();
        }
        if ($this->activeFieldsMemo !== null) {
            return $this->activeFieldsMemo;
        }
        $this->ensureDefaults();

        return $this->activeFieldsMemo = Cache::remember(self::CACHE_KEY, 120, function () {
            $out = [];
            foreach (MatchingField::query()->orderBy('id')->get() as $f) {
                $out[$f->field_key] = [
                    'label' => (string) $f->label,
                    'type' => (string) $f->type,
                    'category' => (string) $f->category,
                    'is_active' => (bool) $f->is_active,
                    'weight' => (int) $f->weight,
                    'max_weight' => (int) $f->max_weight,
                ];
            }

            return $out;
        });
    }

    public function weightFor(string $fieldKey): int
    {
        $map = $this->getActiveFields();

        return (int) ($map[$fieldKey]['weight'] ?? $this->legacyFields()[$fieldKey]['weight'] ?? 0);
    }

    public function fieldEnabled(string $fieldKey): bool
    {
        $map = $this->getActiveFields();

        return ($map[$fieldKey]['is_active'] ?? $this->legacyFields()[$fieldKey]['is_active'] ?? true) === true;
    }

    /**
     * Sum of weights for active scoring fields (used for caps / validation).
     */
    public function sumActiveFieldWeights(): int
    {
        $sum = 0;
        foreach ($this->getActiveFields() as $key => $row) {
            if ($row['is_active']) {
                $sum += max(0, (int) $row['weight']);
            }
        }

        return $sum;
    }

    public function candidatePoolLimit(): int
    {
        $v = $this->runtimeValue('candidate_pool_limit');
        if ($v !== null && $v !== '') {
            return max(1, (int) $v);
        }

        return max(1, (int) config('matching.candidate_pool_limit', 200));
    }

    public function persistMatchesEnabled(): bool
    {
        $v = $this->runtimeValue('persist_cache');
        if ($v !== null) {
            return (bool) $v;
        }

        return (bool) config('matching.persist_cache', false);
    }

    /**
     * Score at or above which a Suchak-facing pairing is labelled a strong preliminary fit.
     * Tunable via the versioned `runtime` engine config; never hardcoded at the call site.
     */
    public function suchakStrongFitScore(): int
    {
        return $this->boundedRuntimeScore('suchak_strong_fit_score', 70);
    }

    /** Score at or above which a Suchak-facing pairing is labelled a possible preliminary fit. */
    public function suchakPossibleFitScore(): int
    {
        return $this->boundedRuntimeScore('suchak_possible_fit_score', 45);
    }

    /** Score below which a Suchak-facing pairing is not surfaced as a suggestion at all. */
    public function suchakMinFitScore(): int
    {
        return $this->boundedRuntimeScore('suchak_min_fit_score', 30);
    }

    /**
     * A scored field earning less than this percentage of its configured weight is reported to the
     * Suchak as a review note ("weak signal") rather than silently disappearing from the reasons list.
     */
    public function suchakWeakSignalPercent(): int
    {
        return $this->boundedRuntimeScore('suchak_weak_signal_percent', 35);
    }

    private function boundedRuntimeScore(string $key, int $fallback): int
    {
        $v = $this->runtimeValue($key);
        if ($v !== null && $v !== '') {
            return max(0, min(100, (int) $v));
        }

        return $fallback;
    }

    public function behaviorMaxPoints(): int
    {
        $v = $this->runtimeValue('behavior_max_points');
        if ($v !== null && $v !== '') {
            return max(0, min(50, (int) $v));
        }

        return 15;
    }

    /**
     * @return array<string, array{mode: string, preferred_penalty_points: int}>
     */
    public function getHardFilters(): array
    {
        if (! SchemaPresence::hasTable('matching_hard_filters')) {
            return $this->legacyHardFilters();
        }
        $this->ensureDefaults();
        $out = [];
        foreach (MatchingHardFilter::query()->orderBy('filter_key')->get() as $row) {
            $out[$row->filter_key] = [
                'mode' => (string) $row->mode,
                'preferred_penalty_points' => (int) $row->preferred_penalty_points,
            ];
        }

        return $out;
    }

    public function hardFilterMode(string $filterKey): string
    {
        $all = $this->getHardFilters();

        return (string) ($all[$filterKey]['mode'] ?? MatchingHardFilter::MODE_OFF);
    }

    public function preferredPenalty(string $filterKey): int
    {
        $all = $this->getHardFilters();

        return max(0, (int) ($all[$filterKey]['preferred_penalty_points'] ?? 10));
    }

    /**
     * @return array<string, array{weight: int, decay_days: int, is_active: bool}>
     */
    public function getBehaviorWeights(): array
    {
        if (! SchemaPresence::hasTable('matching_behavior_weights')) {
            return [];
        }
        $this->ensureDefaults();
        $out = [];
        foreach (MatchingBehaviorWeight::query()->orderBy('action')->get() as $row) {
            $out[$row->action] = [
                'weight' => (int) $row->weight,
                'decay_days' => max(1, (int) $row->decay_days),
                'is_active' => (bool) $row->is_active,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{value: int, max_cap: int, is_active: bool, meta: ?array}>
     */
    public function getBoostRules(): array
    {
        if (! SchemaPresence::hasTable('matching_boost_rules')) {
            return [];
        }
        $this->ensureDefaults();
        $out = [];
        foreach (MatchingBoostRule::query()->orderBy('boost_type')->get() as $row) {
            $out[$row->boost_type] = [
                'value' => (int) $row->value,
                'max_cap' => (int) $row->max_cap,
                'is_active' => (bool) $row->is_active,
                'meta' => $row->meta,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function captureSnapshotForVersioning(): array
    {
        if (! $this->tablesExist()) {
            return [];
        }
        $this->ensureDefaults();

        return [
            'fields' => MatchingField::query()->orderBy('id')->get()->map(fn ($m) => $m->toArray())->all(),
            'hard_filters' => MatchingHardFilter::query()->orderBy('id')->get()->map(fn ($m) => $m->toArray())->all(),
            'behavior_weights' => MatchingBehaviorWeight::query()->orderBy('id')->get()->map(fn ($m) => $m->toArray())->all(),
            'boost_rules' => MatchingBoostRule::query()->orderBy('id')->get()->map(fn ($m) => $m->toArray())->all(),
            'engine_configs' => MatchingEngineConfig::query()->orderBy('id')->get()->map(fn ($m) => $m->toArray())->all(),
        ];
    }

    private function runtimeValue(string $key): mixed
    {
        if (array_key_exists($key, $this->runtimeValueMemo)) {
            return $this->runtimeValueMemo[$key];
        }
        if (! SchemaPresence::hasTable('matching_engine_configs')) {
            return $this->runtimeValueMemo[$key] = null;
        }
        $row = MatchingEngineConfig::query()->where('config_key', 'runtime')->where('is_active', true)->first();
        if (! $row || ! is_array($row->config_value)) {
            return $this->runtimeValueMemo[$key] = null;
        }

        return $this->runtimeValueMemo[$key] = ($row->config_value[$key] ?? null);
    }

    /**
     * @return array<string, array{label: string, type: string, category: string, is_active: bool, weight: int, max_weight: int}>
     */
    private function legacyFields(): array
    {
        return [
            'age' => ['label' => 'Age', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 17, 'max_weight' => 40],
            'location' => ['label' => 'Location', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 12, 'max_weight' => 30],
            'education' => ['label' => 'Education', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 12, 'max_weight' => 30],
            'occupation' => ['label' => 'Occupation', 'type' => 'similarity', 'category' => 'secondary', 'is_active' => true, 'weight' => 8, 'max_weight' => 25],
            'community' => ['label' => 'Community', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 16, 'max_weight' => 40],
            'preferences' => ['label' => 'Preferences', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 12, 'max_weight' => 40],
            'marital_status' => ['label' => 'Marital status', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 9, 'max_weight' => 25],
            'height' => ['label' => 'Height', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 8, 'max_weight' => 25],
            'diet' => ['label' => 'Diet', 'type' => 'similarity', 'category' => 'core', 'is_active' => true, 'weight' => 6, 'max_weight' => 25],
            // Also the live fallback for an EXISTING install: `ensureDefaults()` only seeds when the
            // table is empty, so a database that predates this row resolves the weight from here.
            'gunamilan' => ['label' => 'Gunamilan', 'type' => 'similarity', 'category' => 'secondary', 'is_active' => true, 'weight' => self::GUNAMILAN_WEIGHT, 'max_weight' => 25],
        ];
    }

    /**
     * @return array<string, array{mode: string, preferred_penalty_points: int}>
     */
    private function legacyHardFilters(): array
    {
        return [
            'religion' => [
                'mode' => config('matching.strict_religion_filter', false) ? MatchingHardFilter::MODE_STRICT : MatchingHardFilter::MODE_OFF,
                'preferred_penalty_points' => 12,
            ],
            'marital_status' => [
                'mode' => config('matching.strict_marital_filter', false) ? MatchingHardFilter::MODE_STRICT : MatchingHardFilter::MODE_OFF,
                'preferred_penalty_points' => 15,
            ],
            'caste' => ['mode' => MatchingHardFilter::MODE_OFF, 'preferred_penalty_points' => 12],
        ];
    }
}
