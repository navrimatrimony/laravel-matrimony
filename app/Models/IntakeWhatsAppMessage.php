<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeWhatsAppMessage extends Model
{
    protected $table = 'intake_whatsapp_messages';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_STATUS = 'status';

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_VIDEO = 'video';

    public const TYPE_INTERACTIVE = 'interactive';

    public const TYPE_STATUS = 'status';

    public const TYPE_UNKNOWN = 'unknown';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    public const ALLOWED_MESSAGE_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_IMAGE,
        self::TYPE_DOCUMENT,
        self::TYPE_AUDIO,
        self::TYPE_VIDEO,
        self::TYPE_INTERACTIVE,
        self::TYPE_STATUS,
        self::TYPE_UNKNOWN,
    ];

    protected $fillable = [
        'intake_whatsapp_session_id',
        'biodata_intake_id',
        'direction',
        'wa_message_id',
        'message_type',
        'text_body',
        'media_id',
        'media_mime_type',
        'media_filename',
        'media_storage_path',
        'processing_status',
        'failure_code',
        'failure_message',
        'webhook_payload_json',
        'received_at',
        'sent_at',
        'processed_at',
    ];

    protected $casts = [
        'webhook_payload_json' => 'array',
        'received_at' => 'datetime',
        'sent_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(IntakeWhatsAppSession::class, 'intake_whatsapp_session_id');
    }

    public function biodataIntake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class, 'biodata_intake_id');
    }
}
