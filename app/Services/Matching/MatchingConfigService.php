<?php

namespace App\Services\Matching;

use App\Models\MatchingBehaviorWeight;
use App\Models\MatchingBoostRule;
use App\Models\MatchingEngineConfig;
use App\Models\MatchingField;
use App\Models\MatchingHardFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * DB-backed matching engine configuration with cache and safe fallbacks when tables are empty.
 */
class MatchingConfigService
{
    public const CACHE_KEY = 'matching_engine_config_snapshot_v1';

    private static bool $defaultsEnsured = false;

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function tablesExist(): bool
    {
        return Schema::hasTable('matching_fields');
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
        $this->ensureDefaults();

        return Cache::remember(self::CACHE_KEY, 120, function () {
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
        if (! Schema::hasTable('matching_hard_filters')) {
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
        if (! Schema::hasTable('matching_behavior_weights')) {
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
        if (! Schema::hasTable('matching_boost_rules')) {
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
        if (! Schema::hasTable('matching_engine_configs')) {
            return null;
        }
        $row = MatchingEngineConfig::query()->where('config_key', 'runtime')->where('is_active', true)->first();
        if (! $row || ! is_array($row->config_value)) {
            return null;
        }

        return $row->config_value[$key] ?? null;
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
