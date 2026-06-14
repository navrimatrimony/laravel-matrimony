<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakMessageTemplate extends Model
{
    use HasFactory;

    public const CATEGORY_INTRODUCTION = 'introduction';
    public const CATEGORY_PAYMENT = 'payment';
    public const CATEGORY_CONSENT = 'consent';
    public const CATEGORY_DISPUTE = 'dispute';
    public const CATEGORY_PRIVACY = 'privacy';
    public const CATEGORY_FOLLOW_UP = 'follow_up';

    public const CATEGORIES = [
        self::CATEGORY_INTRODUCTION,
        self::CATEGORY_PAYMENT,
        self::CATEGORY_CONSENT,
        self::CATEGORY_DISPUTE,
        self::CATEGORY_PRIVACY,
        self::CATEGORY_FOLLOW_UP,
    ];

    public const CHANNEL_WHATSAPP_COPY = 'whatsapp_copy';
    public const CHANNEL_SMS_COPY = 'sms_copy';
    public const CHANNEL_IN_APP_COPY = 'in_app_copy';

    public const CHANNELS = [
        self::CHANNEL_WHATSAPP_COPY,
        self::CHANNEL_SMS_COPY,
        self::CHANNEL_IN_APP_COPY,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    public const POLICY_SAFE = 'safe';

    protected $table = 'suchak_message_templates';

    protected $fillable = [
        'template_key',
        'template_title',
        'template_title_mr',
        'template_category',
        'template_channel',
        'template_status',
        'policy_status',
        'body_text',
        'body_text_mr',
        'usage_guidance',
        'usage_guidance_mr',
        'created_by_admin_user_id',
        'admin_audit_log_id',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(SuchakMessageTemplateUsage::class, 'message_template_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak message templates cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak message templates cannot be deleted.');
    }
}
