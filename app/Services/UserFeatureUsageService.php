<?php

namespace App\Services;

use App\Models\UserFeatureUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class UserFeatureUsageService
{
    /**
     * Increment usage for the current time bucket (e.g. current calendar month for monthly).
     * Uses upsert so the row is unique per user, feature, period type, and bucket start.
     *
     * @param  int  $delta  Must be >= 1
     * @return int New total used_count for the bucket
     */
    public function incrementUsage(int $userId, string $featureKey, int $delta = 1, string $period = UserFeatureUsage::PERIOD_MONTHLY, ?CarbonInterface $at = null): int
    {
        if ($delta < 1) {
            throw new InvalidArgumentException('delta must be >= 1');
        }

        $periodStart = $this->resolvePeriodStart($period, $at ?? Carbon::now());
        $periodEnd = $this->resolvePeriodEnd($period, $periodStart);
        $d = (int) $delta;

        $startStr = $periodStart->toDateString();
        $endStr = $periodEnd->toDateString();

        return (int) DB::transaction(function () use ($userId, $featureKey, $period, $startStr, $endStr, $d) {
            try {
                $usage = UserFeatureUsage::query()->updateOrCreate(
                    [
                        'user_id' => $userId,
                        'feature_key' => $featureKey,
                        'period' => $period,
                        'period_start' => $startStr,
                    ],
                    [
                        'period_end' => $endStr,
                    ]
                );
                $usage->increment('used_count', $d);

                return $usage->fresh()->used_count;
            } catch (Throwable $e) {
                // Rare race: two txs inserted the same bucket; unique index fires — retry once with existing row.
                if ($this->isDuplicateKeyException($e)) {
                    $usage = UserFeatureUsage::query()
                        ->where('user_id', $userId)
                        ->where('feature_key', $featureKey)
                        ->where('period', $period)
                        ->whereDate('period_start', $startStr)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $usage->increment('used_count', $d);

                    return $usage->fresh()->used_count;
                }
                throw $e;
            }
        });
    }

    /**
     * Current bucket total (0 if no row yet).
     */
    public function getUsage(int $userId, string $featureKey, string $period = UserFeatureUsage::PERIOD_MONTHLY, ?CarbonInterface $at = null): int
    {
        $periodStart = $this->resolvePeriodStart($period, $at ?? Carbon::now());

        $periodEnd = $this->resolvePeriodEnd($period, $periodStart);

        return (int) UserFeatureUsage::query()
            ->where('user_id', $userId)
            ->where('feature_key', $featureKey)
            ->where('period', $period)
            ->whereDate('period_start', $periodStart->toDateString())
            ->value('used_count') ?? 0;
    }

    /**
     * First instant of the bucket that contains $at (app timezone).
     */
    public function resolvePeriodStart(string $period, CarbonInterface $at): CarbonInterface
    {
        return match ($period) {
            UserFeatureUsage::PERIOD_MONTHLY => $at->copy()->startOfMonth()->startOfDay(),
            UserFeatureUsage::PERIOD_DAILY => $at->copy()->startOfDay(),
            default => throw new InvalidArgumentException("Unsupported usage period: {$period}"),
        };
    }

    /**
     * Inclusive end date for the bucket (last day of month for monthly; same calendar day for daily).
     */
    public function resolvePeriodEnd(string $period, CarbonInterface $periodStart): CarbonInterface
    {
        return match ($period) {
            UserFeatureUsage::PERIOD_MONTHLY => $periodStart->copy()->endOfMonth()->startOfDay(),
            UserFeatureUsage::PERIOD_DAILY => $periodStart->copy()->startOfDay(),
            default => throw new InvalidArgumentException("Unsupported usage period: {$period}"),
        };
    }

    private function isDuplicateKeyException(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'unique constraint')
            || str_contains($msg, 'duplicate entry')
            || str_contains($msg, 'integrity constraint violation');
    }
}
