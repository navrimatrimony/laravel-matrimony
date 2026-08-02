<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;
use RuntimeException;

class SuchakPipeline extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_EXPIRED,
        self::STATUS_CLOSED,
        self::STATUS_CONVERTED,
        self::STATUS_CANCELLED,
    ];

    public const SLA_WITHIN = 'within_sla';
    public const SLA_EXPIRED = 'expired';

    protected $table = 'suchak_pipelines';

    /**
     * Where a pipeline came from. Exactly one is set on any row; {@see assertExactlyOneOrigin()}
     * enforces that on every save.
     *
     *  - `request_id`               a MEMBER approached a represented candidate and picked this
     *                               Suchak (SuchakRequestPipelineService::createRequest).
     *  - `collaboration_request_id` two SUCHAKS formed an engagement and the customer-owning side
     *                               accepted it (…::openPipelineForEngagement). Blueprint 6.1 —
     *                               the engagement IS SuchakCollaborationRequest +
     *                               SuchakCommissionAgreement; there is no engagements table.
     *
     * @var list<string>
     */
    public const ORIGIN_COLUMNS = [
        'request_id',
        'collaboration_request_id',
    ];

    protected $fillable = [
        'request_id',
        'collaboration_request_id',
        'target_matrimony_profile_id',
        'requesting_matrimony_profile_id',
        'selected_suchak_account_id',
        'representation_id',
        'pipeline_status',
        'attribution_locked_at',
        'lock_expires_at',
        'sla_status',
        'converted_at',
        'closed_at',
    ];

    protected $casts = [
        'attribution_locked_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * A pipeline must name exactly one origin. Both halves matter: a row that names none is a
     * funnel entry nobody can trace back to an act, and a row that names both would let a member's
     * request and a Suchak engagement claim the same attribution lock over one pair.
     *
     * Lives on `saving` rather than in a CHECK constraint because MySQL and SQLite cannot both be
     * given the same one through Laravel's schema builder — the same reason, and the same shape,
     * as SuchakCollaborationStageEvent::assertOwnership().
     */
    protected static function booted(): void
    {
        static::saving(function (self $pipeline): void {
            $pipeline->assertExactlyOneOrigin();
        });
    }

    public function assertExactlyOneOrigin(): void
    {
        $named = array_values(array_filter(
            self::ORIGIN_COLUMNS,
            fn (string $column): bool => $this->{$column} !== null,
        ));

        if ($named === []) {
            throw new InvalidArgumentException(
                'A Suchak pipeline must name where it came from; this one names neither a member request nor an engagement.'
            );
        }

        if (count($named) > 1) {
            throw new InvalidArgumentException(
                'A Suchak pipeline comes from exactly one origin; this one names '.implode(' and ', $named).'.'
            );
        }
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRequest::class, 'request_id');
    }

    /**
     * The engagement this pipeline was opened for, or null on every member-born pipeline.
     */
    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function isEngagementBorn(): bool
    {
        return $this->collaboration_request_id !== null;
    }

    public function targetMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_matrimony_profile_id');
    }

    public function requestingMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'requesting_matrimony_profile_id');
    }

    public function selectedSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'selected_suchak_account_id');
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakPipelineEvent::class, 'pipeline_id');
    }

    /**
     * A pair may meet more than once (D24 — an arranged re-visit is charged at
     * the same rate). This was a hasOne backed by `unique(pipeline_id)` until
     * 2026-08-01; the unique is gone and so is the one-meeting assumption.
     */
    public function visitConfirmations(): HasMany
    {
        return $this->hasMany(SuchakVisitConfirmation::class, 'pipeline_id')
            ->orderBy('meeting_sequence');
    }

    /**
     * The meeting that decides what the pair may do next — scheduling is blocked
     * while it is still open, and it is the one a screen should show first.
     */
    public function latestVisitConfirmation(): HasOne
    {
        return $this->hasOne(SuchakVisitConfirmation::class, 'pipeline_id')
            ->latestOfMany('meeting_sequence');
    }

    public function isPastSla(?CarbonInterface $at = null): bool
    {
        return $this->lock_expires_at !== null && $this->lock_expires_at->lte($at ?? now());
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak pipeline records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak pipeline records cannot be deleted.');
    }
}
