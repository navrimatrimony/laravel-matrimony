<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One installment of the success fee, frozen with the customer agreement (blueprint 7.4, D25).
 *
 * The plan columns (sort_order, trigger_stage_key, share_percent, is_final_tranche) are a
 * SNAPSHOT: written once when the agreement revision is created, and covered by
 * suchak_customer_agreements.agreement_snapshot_hash. Editing one after acceptance makes the
 * stored digest stop matching, exactly as editing the package price or either meeting fee does
 * — that hash, and not a model guard, is the freeze (the same arrangement already governs
 * SuchakCustomerAgreementStage and SuchakCustomerAgreementDeliverable).
 *
 * The ledger columns (released_*, customer_payment_id, settled_at) stay writable for the life
 * of the agreement. That is M9: a tranche already paid stands whichever match triggered it, and
 * only the unpaid rows fire on a later match.
 */
class SuchakSuccessFeeTranche extends Model
{
    use HasFactory;

    /**
     * Columns that form the agreed split. Anything listed here is inside the snapshot digest;
     * anything not listed is ledger state and may move after acceptance.
     *
     * @var list<string>
     */
    public const PLAN_COLUMNS = [
        'sort_order',
        'trigger_stage_key',
        'share_percent',
        'is_final_tranche',
    ];

    protected $table = 'suchak_success_fee_tranches';

    protected $fillable = [
        'customer_agreement_id',
        'sort_order',
        'trigger_stage_key',
        'share_percent',
        'is_final_tranche',
        'released_by_collaboration_request_id',
        'released_by_stage_event_id',
        'released_at',
        'customer_payment_id',
        'settled_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'share_percent' => 'decimal:2',
        'is_final_tranche' => 'boolean',
        'released_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
    }

    public function releasedByCollaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'released_by_collaboration_request_id');
    }

    public function releasedByStageEvent(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationStageEvent::class, 'released_by_stage_event_id');
    }

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerPayment::class, 'customer_payment_id');
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * Settled means the customer has actually paid this tranche. M9 turns on exactly this
     * predicate: a settled tranche is never re-charged by a later match, a merely released one
     * still is owed.
     */
    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * Whether this row is spent for M9 purposes — released against some match, or already paid.
     * A plan may not be re-cut once any of its rows has reached this state.
     */
    public function isCommitted(): bool
    {
        return $this->isReleased() || $this->isSettled();
    }

    /**
     * {@see isCommitted()} as a query, so the two can never disagree.
     *
     * M9's customer-level check (SuchakSuccessFeeTrancheService::committedStagesOnOtherChains)
     * has to ask "is anything committed on this family's OTHER agreement chain" across rows it
     * has no reason to hydrate. Written as a second `whereNotNull` pair at the call site, that
     * predicate would be free to drift from the model's — and the drift would be invisible until
     * a family was charged the same rung twice.
     */
    public function scopeCommitted(Builder $query): Builder
    {
        return $query->where(static function (Builder $inner): void {
            $inner->whereNotNull('released_at')->orWhereNotNull('settled_at');
        });
    }
}
