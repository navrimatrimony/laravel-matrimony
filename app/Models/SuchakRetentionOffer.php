<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakRetentionOffer extends Model
{
    use HasFactory;

    public const TYPE_RENEWAL_DISCOUNT = 'renewal_discount';
    public const TYPE_REVENUE_SHARE = 'revenue_share';

    public const TYPES = [
        self::TYPE_RENEWAL_DISCOUNT,
        self::TYPE_REVENUE_SHARE,
    ];

    public const STATUS_OFFERED = 'offered';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OFFERED,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_retention_offers';

    protected $fillable = [
        'suchak_account_id',
        'monthly_value_report_id',
        'offer_type',
        'offer_status',
        'discount_percent',
        'revenue_share_percent',
        'offer_amount',
        'currency',
        'offer_note',
        'offer_note_mr',
        'offered_by_admin_user_id',
        'admin_audit_log_id',
        'offered_at',
        'responded_at',
        'response_note',
        'response_note_mr',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'revenue_share_percent' => 'decimal:2',
        'offer_amount' => 'decimal:2',
        'offered_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function monthlyValueReport(): BelongsTo
    {
        return $this->belongsTo(SuchakMonthlyValueReport::class, 'monthly_value_report_id');
    }

    public function offeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'admin_audit_log_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak retention offers cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak retention offers cannot be deleted.');
    }
}
