<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakFeatureSuspension extends Model
{
    use HasFactory;

    public const FEATURE_UPLOAD = 'upload';
    public const FEATURE_PDF = 'pdf';
    public const FEATURE_PAYMENT = 'payment';
    public const FEATURE_PAYOUT = 'payout';
    public const FEATURE_REFERRAL = 'referral';
    public const FEATURE_COLLABORATION = 'collaboration';
    public const FEATURE_PUBLIC_REQUEST = 'public_request';

    public const FEATURES = [
        self::FEATURE_UPLOAD,
        self::FEATURE_PDF,
        self::FEATURE_PAYMENT,
        self::FEATURE_PAYOUT,
        self::FEATURE_REFERRAL,
        self::FEATURE_COLLABORATION,
        self::FEATURE_PUBLIC_REQUEST,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RELEASED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_feature_suspensions';

    protected $fillable = [
        'suchak_account_id',
        'feature_key',
        'suspension_status',
        'reason',
        'reason_mr',
        'created_by_admin_user_id',
        'created_admin_audit_log_id',
        'released_by_admin_user_id',
        'released_admin_audit_log_id',
        'released_at',
        'release_reason',
        'release_reason_mr',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function scopeActiveForFeature(Builder $query, SuchakAccount $account, string $featureKey): Builder
    {
        return $query
            ->where('suchak_account_id', $account->id)
            ->where('feature_key', $featureKey)
            ->where('suspension_status', self::STATUS_ACTIVE);
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function releasedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_admin_user_id');
    }

    public function createdAdminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'created_admin_audit_log_id');
    }

    public function releasedAdminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'released_admin_audit_log_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak feature suspension records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak feature suspension records cannot be deleted.');
    }
}
