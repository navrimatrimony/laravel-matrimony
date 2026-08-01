<?php

namespace App\Models;

use App\Support\MoneyFormat;
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

    /**
     * A meeting still IN FLIGHT — the pair may not schedule the next one yet.
     *
     * `completed` here literally means completed-but-unconfirmed: the moment the
     * confirmation policy is satisfied, refreshVisitStatus() moves the row to
     * `confirmed`, so a row can only sit at `completed` while somebody's
     * confirmation is still outstanding. M4 — no fee falls due without the
     * customer's confirmation — is why that counts as open: a second meeting
     * must not be arranged on top of one the family has not yet acknowledged.
     * `disputed` is open for the same reason plus section 7.2's leverage.
     *
     * `confirmed` and `payout_qualified` are settled, and D24 says the pair may
     * meet again at the same rate. `cancelled` is over: it is written by
     * {@see \App\Modules\Suchak\Services\SuchakVisitConfirmationService::cancelVisit()}
     * and is what stops a meeting that never happened from blocking the pair
     * forever — with `scheduled` counted as open, the first no-show would
     * otherwise strand the pipeline with no way back.
     */
    public const OPEN_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_COMPLETED,
        self::STATUS_DISPUTED,
    ];

    /**
     * Offline and online meetings are two fully independent prices (D2), never a
     * ratio of each other, so which one a meeting was decides which rate froze
     * onto `fee_amount`.
     */
    public const MODE_OFFLINE = 'offline';
    public const MODE_ONLINE = 'online';

    public const MEETING_MODES = [
        self::MODE_OFFLINE,
        self::MODE_ONLINE,
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
        'helper_suchak_account_id',
        'request_id',
        'representation_id',
        'target_matrimony_profile_id',
        'requesting_matrimony_profile_id',
        'payment_context_id',
        'customer_context_id',
        'customer_agreement_id',
        'platform_payout_id',
        'dispute_id',
        'payout_hold_id',
        'visit_status',
        'confirmation_policy_mode',
        'meeting_sequence',
        'meeting_mode',
        'fee_amount',
        'fee_currency',
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
        'meeting_sequence' => 'integer',
        'fee_amount' => 'decimal:2',
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

    /**
     * Whose candidate was met, when that is not the arranging Suchak's own.
     * Null on an ordinary meeting; set on a marketplace one.
     */
    public function helperSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'helper_suchak_account_id');
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

    /**
     * The agreement revision that quoted `fee_amount` / `fee_currency`.
     *
     * A frozen figure with no source is not auditable a year later, and there
     * was no other way to reach it: the meeting hangs off `payment_context_id`,
     * and a payment context names no package and no agreement.
     */
    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
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

    /**
     * Is a human going to be billed for this meeting?
     *
     * M4 hangs off exactly this question — "no fee falls due without the
     * customer's confirmation" is a rule about MONEY, so the answer has to come
     * from the frozen quote and not from the confirmation policy in force. A
     * quoted zero is not a charge; a null is not a charge either (nothing was
     * agreed for this meeting).
     */
    public function isFeeBearing(): bool
    {
        return $this->fee_amount !== null && (float) $this->fee_amount > 0;
    }

    /**
     * The frozen quote as text, amount and unit together.
     *
     * Exposed here rather than left to callers because the two halves must never
     * be paired by hand: `MoneyFormat::amount($visit->fee_amount)` alone silently
     * defaults to '₹' and renders a USD meeting as rupees, which is the whole
     * reason `fee_currency` exists. `MoneyFormat` stays the single formatter —
     * this only binds it to the right unit.
     *
     * The INR fallback covers a row written before `fee_currency` existed; every
     * such row was rupees, because that was the only currency the engine could
     * price in.
     */
    public function getFeeDisplayAttribute(): ?string
    {
        return MoneyFormat::amount($this->fee_amount, $this->fee_currency ?? 'INR');
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
