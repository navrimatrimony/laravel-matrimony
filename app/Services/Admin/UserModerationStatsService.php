<?php

namespace App\Services\Admin;

use App\Models\UserModerationStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserModerationStatsService
{
    public function recordUpload(?int $userId): void
    {
        if ($userId === null || $userId <= 0 || ! Schema::hasTable('user_moderation_stats')) {
            return;
        }

        DB::transaction(function () use ($userId): void {
            $stat = UserModerationStat::query()->firstOrCreate(
                ['user_id' => $userId],
                [
                    'total_uploads' => 0,
                    'total_approved' => 0,
                    'total_rejected' => 0,
                    'total_review' => 0,
                    'last_upload_at' => null,
                    'risk_score' => 0,
                    'is_flagged' => false,
                ]
            );
            $stat->increment('total_uploads');
            $stat->forceFill(['last_upload_at' => now()])->save();
            $this->recomputeRisk($stat->fresh());
        });
    }

    /**
     * @param  'approved'|'rejected'|'review'  $outcome
     */
    public function recordModerationOutcome(?int $userId, string $outcome): void
    {
        if ($userId === null || $userId <= 0 || ! Schema::hasTable('user_moderation_stats')) {
            return;
        }

        if (! in_array($outcome, ['approved', 'rejected', 'review'], true)) {
            return;
        }

        DB::transaction(function () use ($userId, $outcome): void {
            $stat = UserModerationStat::query()->firstOrCreate(
                ['user_id' => $userId],
                [
                    'total_uploads' => 0,
                    'total_approved' => 0,
                    'total_rejected' => 0,
                    'total_review' => 0,
                    'last_upload_at' => null,
                    'risk_score' => 0,
                    'is_flagged' => false,
                ]
            );

            match ($outcome) {
                'approved' => $stat->increment('total_approved'),
                'rejected' => $stat->increment('total_rejected'),
                'review' => $stat->increment('total_review'),
            };

            $this->recomputeRisk($stat->fresh());
        });
    }

    public function recomputeRisk(?UserModerationStat $stat): void
    {
        if ($stat === null) {
            return;
        }

        $uploads = max(0, (int) $stat->total_uploads);
        $rejected = (int) $stat->total_rejected;
        $review = (int) $stat->total_review;

        $risk = $uploads > 0
            ? ($rejected * 2 + $review * 1.2) / $uploads
            : 0.0;

        $flagged = $risk > 1.2 || $rejected >= 5;

        $stat->forceFill([
            'risk_score' => round($risk, 4),
            'is_flagged' => $flagged,
        ])->save();
    }
}
