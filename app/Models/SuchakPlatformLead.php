<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPlatformLead extends Model
{
    use HasFactory;

    public const TYPE_PROFILE_MATCH = 'profile_match';
    public const TYPE_PACKAGE_LEAD = 'package_lead';
    public const TYPE_MARKETPLACE_REQUEST = 'marketplace_request';
    public const TYPE_ADMIN_GENERATED = 'admin_generated';

    public const TYPES = [
        self::TYPE_PROFILE_MATCH,
        self::TYPE_PACKAGE_LEAD,
        self::TYPE_MARKETPLACE_REQUEST,
        self::TYPE_ADMIN_GENERATED,
    ];

    public const SOURCE_PLATFORM = 'platform';
    public const SOURCE_ADMIN = 'admin';

    public const SOURCES = [
        self::SOURCE_PLATFORM,
        self::SOURCE_ADMIN,
    ];

    public const POLICY_AREA_COMMUNITY_ROTATION = 'area_community_rotation';
    public const POLICY_AREA_FIRST = 'area_first';
    public const POLICY_COMMUNITY_FIRST = 'community_first';
    public const POLICY_ADMIN_OVERRIDE = 'admin_override';

    public const POLICIES = [
        self::POLICY_AREA_COMMUNITY_ROTATION,
        self::POLICY_AREA_FIRST,
        self::POLICY_COMMUNITY_FIRST,
        self::POLICY_ADMIN_OVERRIDE,
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_ALLOCATED = 'allocated';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CONVERTED = 'converted';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ALLOCATED,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_CONVERTED,
    ];

    protected $table = 'suchak_platform_leads';

    protected $fillable = [
        'lead_type',
        'lead_source',
        'lead_status',
        'allocation_policy',
        'allocation_sla_hours',
        'requesting_user_id',
        'requesting_matrimony_profile_id',
        'target_matrimony_profile_id',
        'service_context',
        'district_id',
        'taluka_id',
        'city_id',
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'lead_title',
        'lead_note',
        'created_by_admin_user_id',
        'opened_at',
        'allocated_at',
        'closed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'allocation_sla_hours' => 'integer',
        'opened_at' => 'datetime',
        'allocated_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    public function requestingProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'requesting_matrimony_profile_id');
    }

    public function targetProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_matrimony_profile_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SuchakPlatformLeadAllocation::class, 'platform_lead_id')
            ->orderByDesc('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakLeadAllocationEvent::class, 'platform_lead_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak platform lead records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak platform lead records cannot be deleted.');
    }
}
