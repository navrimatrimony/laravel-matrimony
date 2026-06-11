<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakOfflineCamp extends Model
{
    use HasFactory;

    public const TYPE_BIODATA_DRIVE = 'biodata_drive';
    public const TYPE_OFFLINE_CAMP = 'offline_camp';
    public const TYPE_COMMUNITY_MEET = 'community_meet';

    public const TYPES = [
        self::TYPE_BIODATA_DRIVE,
        self::TYPE_OFFLINE_CAMP,
        self::TYPE_COMMUNITY_MEET,
    ];

    public const STATUS_PLANNED = 'planned';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_offline_camps';

    protected $fillable = [
        'suchak_account_id',
        'camp_key',
        'camp_name',
        'camp_type',
        'camp_status',
        'source_tag',
        'location_label',
        'camp_date',
        'expected_intake_count',
        'privacy_note',
        'created_by_user_id',
        'closed_at',
    ];

    protected $casts = [
        'camp_date' => 'date',
        'expected_intake_count' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function intakeLinks(): HasMany
    {
        return $this->hasMany(SuchakOfflineCampIntakeLink::class, 'offline_camp_id');
    }

    public function packageAssignments(): HasMany
    {
        return $this->hasMany(SuchakOfflineCampPackageAssignment::class, 'offline_camp_id');
    }

    public function conversionReports(): HasMany
    {
        return $this->hasMany(SuchakOfflineCampConversionReport::class, 'offline_camp_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp records cannot be deleted.');
    }
}
