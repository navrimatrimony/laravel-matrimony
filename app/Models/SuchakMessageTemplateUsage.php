<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakMessageTemplateUsage extends Model
{
    use HasFactory;

    public const CONTEXT_GENERAL = 'general';
    public const CONTEXT_PROFILE_INTRODUCTION = 'profile_introduction';
    public const CONTEXT_PAYMENT_REQUEST = 'payment_request';
    public const CONTEXT_CONSENT = 'consent';
    public const CONTEXT_DISPUTE = 'dispute';
    public const CONTEXT_FOLLOW_UP = 'follow_up';

    public const CONTEXTS = [
        self::CONTEXT_GENERAL,
        self::CONTEXT_PROFILE_INTRODUCTION,
        self::CONTEXT_PAYMENT_REQUEST,
        self::CONTEXT_CONSENT,
        self::CONTEXT_DISPUTE,
        self::CONTEXT_FOLLOW_UP,
    ];

    protected $table = 'suchak_message_template_usages';

    protected $fillable = [
        'suchak_account_id',
        'message_template_id',
        'used_by_user_id',
        'usage_context',
        'rendered_body',
        'metadata_json',
        'used_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'used_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(SuchakMessageTemplate::class, 'message_template_id');
    }

    public function usedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak message template usage records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak message template usage records cannot be deleted.');
    }
}
