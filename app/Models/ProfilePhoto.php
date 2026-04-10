<?php

namespace App\Models;

use App\Services\Admin\UserModerationStatsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class ProfilePhoto extends Model
{
    protected $table = 'profile_photos';

    protected $fillable = [
        'profile_id',
        'file_path',
        'is_primary',
        'sort_order',
        'uploaded_via',
        'approved_status',
        'watermark_detected',
        'moderation_scan_json',
        'admin_override_status',
        'admin_override_by',
        'admin_override_at',
        'moderation_version',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'watermark_detected' => 'boolean',
        'moderation_scan_json' => 'array',
        'admin_override_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (ProfilePhoto $photo): void {
            if (! Schema::hasTable('user_moderation_stats')) {
                return;
            }
            $profile = MatrimonyProfile::query()->find($photo->profile_id);
            $uid = $profile?->user_id;
            if ($uid) {
                app(UserModerationStatsService::class)->recordUpload((int) $uid);
            }
        });
    }

    /**
     * Public / search visibility: admin override wins when set (approved | review | rejected).
     *
     * @return 'approved'|'pending'|'rejected'
     */
    public function effectiveApprovedStatus(): string
    {
        $base = (string) ($this->approved_status ?? '');
        if ($base === '') {
            $base = 'pending';
        }

        if (! Schema::hasColumn($this->getTable(), 'admin_override_status')) {
            return in_array($base, ['approved', 'pending', 'rejected'], true) ? $base : 'pending';
        }

        $ov = $this->admin_override_status;
        if ($ov === null || $ov === '') {
            return in_array($base, ['approved', 'pending', 'rejected'], true) ? $base : 'pending';
        }

        return match ($ov) {
            'approved' => 'approved',
            'review' => 'pending',
            'rejected' => 'rejected',
            default => in_array($base, ['approved', 'pending', 'rejected'], true) ? $base : 'pending',
        };
    }

    /**
     * Rows that are effectively approved for viewers (not pending/rejected).
     */
    public function scopeEffectivelyApproved(Builder $query): Builder
    {
        if (! Schema::hasColumn('profile_photos', 'admin_override_status')) {
            return $query->where('approved_status', 'approved');
        }

        return $query->whereRaw("(
            CASE
                WHEN admin_override_status IS NOT NULL THEN
                    CASE admin_override_status
                        WHEN 'approved' THEN 'approved'
                        WHEN 'review' THEN 'pending'
                        WHEN 'rejected' THEN 'rejected'
                        ELSE approved_status
                    END
                ELSE approved_status
            END
        ) = 'approved'");
    }

    /**
     * Rows that are not effectively approved (pending/rejected from a viewer perspective).
     */
    public function scopeWhereNotEffectivelyApproved(Builder $query): Builder
    {
        if (! Schema::hasColumn('profile_photos', 'admin_override_status')) {
            return $query->where('approved_status', '!=', 'approved');
        }

        return $query->whereRaw("NOT (
            CASE
                WHEN admin_override_status IS NOT NULL THEN
                    CASE admin_override_status
                        WHEN 'approved' THEN 'approved'
                        WHEN 'review' THEN 'pending'
                        WHEN 'rejected' THEN 'rejected'
                        ELSE approved_status
                    END
                ELSE approved_status
            END = 'approved'
        )");
    }

    /**
     * Filter by effective moderation outcome (approved | pending | rejected).
     * Mirrors effectiveApprovedStatus() / admin override semantics.
     */
    public function scopeWhereEffectiveOutcome(Builder $query, string $outcome): Builder
    {
        $outcome = in_array($outcome, ['approved', 'pending', 'rejected'], true) ? $outcome : 'pending';

        if (! Schema::hasColumn($this->getTable(), 'admin_override_status')) {
            return $query->where('approved_status', $outcome);
        }

        $case = "(CASE
            WHEN admin_override_status IS NOT NULL THEN
                CASE admin_override_status
                    WHEN 'approved' THEN 'approved'
                    WHEN 'review' THEN 'pending'
                    WHEN 'rejected' THEN 'rejected'
                    ELSE approved_status
                END
            ELSE approved_status
        END)";

        return $query->whereRaw("{$case} = ?", [$outcome]);
    }

    public function scopeOrdered($query)
    {
        return $query
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function moderationLogs(): HasMany
    {
        return $this->hasMany(PhotoModerationLog::class, 'photo_id')->orderByDesc('id');
    }
}

