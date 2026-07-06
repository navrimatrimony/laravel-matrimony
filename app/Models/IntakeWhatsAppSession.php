<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeWhatsAppSession extends Model
{
    protected $table = 'intake_whatsapp_sessions';

    public const STATUS_OPEN = 'open';

    public const STATUS_WAITING_FOR_CONSENT = 'waiting_for_consent';

    public const STATUS_COLLECTING_BIODATA = 'collecting_biodata';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_BLOCKED = 'blocked';

    public const CONSENT_UNKNOWN = 'unknown';

    public const CONSENT_PENDING = 'pending';

    public const CONSENT_GRANTED = 'granted';

    public const CONSENT_DENIED = 'denied';

    public const SOURCE_SURFACE_WHATSAPP = 'whatsapp';

    protected $fillable = [
        'wa_phone_number_id',
        'wa_business_account_id',
        'wa_contact_wa_id',
        'normalized_mobile',
        'linked_user_id',
        'actor_type',
        'source_surface',
        'session_status',
        'current_state',
        'consent_status',
        'last_message_at',
        'closed_at',
        'session_meta_json',
    ];

    protected $casts = [
        'session_meta_json' => 'array',
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(IntakeWhatsAppMessage::class, 'intake_whatsapp_session_id');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
