<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkIntakeBatch extends Model
{
    protected $table = 'bulk_intake_batches';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const POLICY_EXISTING_CHAIN = 'existing_chain';

    public const OCR_POLICY_FREE_OCR_FIRST = 'free_ocr_first';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_PROFILE_USER = 'profile_user';

    public const ACTOR_SUCHAK = 'suchak';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_UNKNOWN = 'unknown';

    public const SURFACE_ADMIN_PANEL = 'admin_panel';

    public const SURFACE_MOBILE_APP = 'mobile_app';

    public const SURFACE_WEBSITE = 'website';

    public const SURFACE_API = 'api';

    public const SURFACE_WHATSAPP = 'whatsapp';

    public const SURFACE_SERVER = 'server';

    public const ALLOWED_ACTOR_TYPES = [
        self::ACTOR_ADMIN,
        self::ACTOR_PROFILE_USER,
        self::ACTOR_SUCHAK,
        self::ACTOR_SYSTEM,
        self::ACTOR_UNKNOWN,
    ];

    public const ALLOWED_SURFACES = [
        self::SURFACE_ADMIN_PANEL,
        self::SURFACE_MOBILE_APP,
        self::SURFACE_WEBSITE,
        self::SURFACE_API,
        self::SURFACE_WHATSAPP,
        self::SURFACE_SERVER,
    ];

    protected $fillable = [
        'uploaded_by_user_id',
        'uploaded_by_actor_type',
        'source_surface',
        'batch_name',
        'batch_status',
        'intake_creation_policy',
        'ocr_policy',
        'total_items',
        'total_files',
        'total_texts',
        'total_intakes_created',
        'total_profiles_created',
        'total_conflicts_generated',
        'total_needs_review',
        'total_failed',
        'ai_cost_estimate',
        'ai_cost_actual',
        'started_at',
        'completed_at',
        'failure_message',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'ai_cost_estimate' => 'float',
        'ai_cost_actual' => 'float',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BulkIntakeBatchItem::class, 'bulk_intake_batch_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
