<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeSourceContext extends Model
{
    protected $table = 'intake_source_contexts';

    public const SOURCE_ADMIN_BULK = 'admin_bulk';

    public const SOURCE_WHATSAPP = 'whatsapp';

    public const SOURCE_SUCHAK = 'suchak';

    public const SOURCE_USER_APP = 'user_app';

    public const SOURCE_ADMIN_MANUAL = 'admin_manual';

    public const SOURCE_SYSTEM = 'system';

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

    public const ALLOWED_SOURCE_TYPES = [
        self::SOURCE_ADMIN_BULK,
        self::SOURCE_WHATSAPP,
        self::SOURCE_SUCHAK,
        self::SOURCE_USER_APP,
        self::SOURCE_ADMIN_MANUAL,
        self::SOURCE_SYSTEM,
    ];

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
        'biodata_intake_id',
        'source_type',
        'source_surface',
        'actor_type',
        'actor_user_id',
        'bulk_intake_batch_id',
        'bulk_intake_batch_item_id',
        'intake_whatsapp_session_id',
        'intake_whatsapp_message_id',
        'external_source_id',
        'idempotency_key',
        'source_meta_json',
    ];

    protected $casts = [
        'source_meta_json' => 'array',
    ];

    public function biodataIntake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class, 'biodata_intake_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BulkIntakeBatch::class, 'bulk_intake_batch_id');
    }

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(BulkIntakeBatchItem::class, 'bulk_intake_batch_item_id');
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(IntakeWhatsAppSession::class, 'intake_whatsapp_session_id');
    }

    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(IntakeWhatsAppMessage::class, 'intake_whatsapp_message_id');
    }
}
