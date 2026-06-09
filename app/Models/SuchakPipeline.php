<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $fillable = [
        'request_id',
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

    public function request(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRequest::class, 'request_id');
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
