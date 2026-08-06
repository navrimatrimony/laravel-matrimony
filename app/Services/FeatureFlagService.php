<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\FeatureFlagAudit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global Feature Flag engine — the ONLY source of truth for module on/off.
 *
 * Call sites must use {@see isEnabled()} / {@see setEnabled()}. Never read
 * feature_flags directly and never scatter environment checks for module gates.
 *
 * Absent-row fallback (safety net only; seeder is the primary source):
 * production → false; every other environment → true.
 */
class FeatureFlagService
{
    private const CACHE_PREFIX = 'feature_flag:enabled:';

    private const CACHE_ALL_KEYS = 'feature_flag:all_keys';

    public function isEnabled(string $key): bool
    {
        $cacheKey = self::CACHE_PREFIX.$key;
        $cached = Cache::get($cacheKey);
        if (is_bool($cached)) {
            return $cached;
        }

        $enabled = $this->resolveEnabled($key);
        Cache::forever($cacheKey, $enabled);

        return $enabled;
    }

    /**
     * @return Collection<int, FeatureFlag>
     */
    public function all(): Collection
    {
        if (! Schema::hasTable('feature_flags')) {
            return collect();
        }

        return FeatureFlag::query()
            ->with(['latestAudit.changedByUser'])
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Flip a flag, write an immutable audit row, and invalidate cache immediately.
     */
    public function setEnabled(
        string $key,
        bool $enabled,
        User $admin,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): FeatureFlag {
        return DB::transaction(function () use ($key, $enabled, $admin, $reason, $ipAddress, $userAgent) {
            /** @var FeatureFlag $flag */
            $flag = FeatureFlag::query()->where('key', $key)->lockForUpdate()->firstOrFail();

            $old = (bool) $flag->enabled;
            if ($old === $enabled) {
                return $flag;
            }

            $flag->enabled = $enabled;
            $flag->save();

            FeatureFlagAudit::query()->create([
                'feature_flag_id' => $flag->id,
                'key' => $flag->key,
                'old_value' => $old,
                'new_value' => $enabled,
                'changed_by' => $admin->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 2000) : null,
                'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                'created_at' => now(),
            ]);

            $this->clearCache($flag->key);

            return $flag->fresh(['latestAudit.changedByUser']);
        });
    }

    public function clearCache(?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget(self::CACHE_PREFIX.$key);
        } else {
            $keys = Cache::get(self::CACHE_ALL_KEYS);
            if (is_array($keys)) {
                foreach ($keys as $knownKey) {
                    Cache::forget(self::CACHE_PREFIX.$knownKey);
                }
            }
            if (Schema::hasTable('feature_flags')) {
                foreach (FeatureFlag::query()->pluck('key') as $knownKey) {
                    Cache::forget(self::CACHE_PREFIX.(string) $knownKey);
                }
            }
        }

        Cache::forget(self::CACHE_ALL_KEYS);
    }

    /**
     * Environment-aware default when the row does not exist yet.
     * Production must never auto-enable a module.
     */
    public function environmentDefault(): bool
    {
        return ! app()->environment('production');
    }

    private function resolveEnabled(string $key): bool
    {
        if (! Schema::hasTable('feature_flags')) {
            return $this->environmentDefault();
        }

        $row = FeatureFlag::query()->where('key', $key)->first();
        if ($row === null) {
            return $this->environmentDefault();
        }

        $this->rememberKnownKey($key);

        return (bool) $row->enabled;
    }

    private function rememberKnownKey(string $key): void
    {
        $keys = Cache::get(self::CACHE_ALL_KEYS, []);
        if (! is_array($keys)) {
            $keys = [];
        }
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::forever(self::CACHE_ALL_KEYS, $keys);
        }
    }
}
