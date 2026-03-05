<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Day-32: Contact grant (receiver approved; sender gets access until valid_until).
 */
class ContactGrant extends Model
{
    protected $table = 'contact_grants';

    protected $fillable = [
        'contact_request_id',
        'granted_scopes',
        'valid_until',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'granted_scopes' => 'array',
        'valid_until' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function contactRequest(): BelongsTo
    {
        return $this->belongsTo(ContactRequest::class);
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isValid(): bool
    {
        if ($this->revoked_at) {
            return false;
        }
        return $this->valid_until->isFuture();
    }
}
