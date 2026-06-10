<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakVisitConfirmation extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_PAYOUT_QUALIFIED = 'payout_qualified';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_COMPLETED,
        self::STATUS_CONFIRMED,
        self::STATUS_DISPUTED,
        self::STATUS_PAYOUT_QUALIFIED,
        self::STATUS_CANCELLED,
    ];

    public const COMPLETION_PENDING = 'pending';
    public const COMPLETION_SUCHAK_MARKED = 'suchak_marked_completed';

    public const COMPLETION_STATUSES = [
        self::COMPLETION_PENDING,
        self::COMPLETION_SUCHAK_MARKED,
    ];

    public const CONFIRMATION_PENDING = 'pending';
    public const CONFIRMATION_CONFIRMED = 'confirmed';
    public const CONFIRMATION_DISPUTED = 'disputed';
    public const CONFIRMATION_NOT_REQUIRED = 'not_required';

    public const CONFIRMATION_STATUSES = [
        self::CONFIRMATION_PENDING,
        self::CONFIRMATION_CONFIRMED,
        self::CONFIRMATION_DISPUTED,
        self::CONFIRMATION_NOT_REQUIRED,
    ];

    public const POLICY_USER_AND_ADMIN = 'user_and_admin';
    public const POLICY_ADMIN_ONLY = 'admin_only';
    public const POLICY_USER_ONLY = 'user_only';

    public const POLICY_MODES = [
        self::POLICY_USER_AND_ADMIN,
        self::POLICY_ADMIN_ONLY,
        self::POLICY_USER_ONLY,
    ];

    public const REFUND_NOT_REQUESTED = 'not_requested';
    public const REFUND_PENDING_REVIEW = 'pending_review';

    public const REFUND_STATUSES = [
        self::REFUND_NOT_REQUESTED,
        self::REFUND_PENDING_REVIEW,
    ];

    protected $table = 'suchak_visit_confirmations';

    protected $fillable = [
        'pipeline_id',
        'suchak_account_id',
        'request_id',
        'representation_id',
        'target_matrimony_profile_id',
        'requesting_matrimony_profile_id',
        'payment_context_id',
        'customer_context_id',
        'platform_payout_id',
        'dispute_id',
        'payout_hold_id',
        'visit_status',
        'confirmation_policy_mode',
        'scheduled_for',
        'scheduled_by_user_id',
        'scheduled_at',
        'schedule_note',
        'suchak_completion_status',
        'suchak_completed_by_user_id',
        'suchak_completed_at',
        'suchak_completion_note',
        'user_confirmation_status',
        'user_confirmed_by_user_id',
        'user_confirmed_at',
        'user_confirmation_note',
        'admin_confirmation_status',
        'admin_confirmed_by_user_id',
        'admin_confirmed_at',
        'admin_confirmation_note',
        'refund_review_status',
        'refund_review_note',
        'payout_qualified_at',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'scheduled_at' => 'datetime',
        'suchak_completed_at' => 'datetime',
        'user_confirmed_at' => 'datetime',
        'admin_confirmed_at' => 'datetime',
        'payout_qualified_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(SuchakPipeline::class, 'pipeline_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRequest::class, 'request_id');
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function targetMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_matrimony_profile_id');
    }

    public function requestingMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'requesting_matrimony_profile_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function platformPayout(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayout::class, 'platform_payout_id');
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(SuchakDispute::class, 'dispute_id');
    }

    public function payoutHold(): BelongsTo
    {
        return $this->belongsTo(SuchakPayoutHold::class, 'payout_hold_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakVisitConfirmationEvent::class, 'visit_confirmation_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak visit confirmation records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak visit confirmation records cannot be deleted.');
    }
}
