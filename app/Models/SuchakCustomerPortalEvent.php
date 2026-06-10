<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCustomerPortalEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_LINK_ISSUED = 'portal_link_issued';
    public const EVENT_LINK_OPENED = 'portal_link_opened';
    public const EVENT_LINK_CLAIMED = 'portal_link_claimed';
    public const EVENT_LINK_REVOKED = 'portal_link_revoked';
    public const EVENT_LINK_EXPIRED = 'portal_link_expired';

    public const EVENTS = [
        self::EVENT_LINK_ISSUED,
        self::EVENT_LINK_OPENED,
        self::EVENT_LINK_CLAIMED,
        self::EVENT_LINK_REVOKED,
        self::EVENT_LINK_EXPIRED,
    ];

    protected $table = 'suchak_customer_portal_events';

    protected $fillable = [
        'customer_portal_link_id',
        'suchak_account_id',
        'customer_context_id',
        'event_type',
        'actor_type',
        'actor_user_id',
        'from_status',
        'to_status',
        'event_note',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function portalLink(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerPortalLink::class, 'customer_portal_link_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer portal events are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer portal events are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Suchak customer portal events are immutable and cannot be modified.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Suchak customer portal events are immutable and cannot be modified.');
        }

        return parent::save($options);
    }
}
