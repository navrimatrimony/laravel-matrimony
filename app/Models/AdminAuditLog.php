<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| AdminAuditLog Model
|--------------------------------------------------------------------------
|
| 👉 Represents admin action audit logs
| 👉 Tracks admin actions with reasons and entity references
|
*/
class AdminAuditLog extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Mass Assignable Fields
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'admin_id',
        'action_type',
        'entity_type',
        'entity_id',
        'reason',
        'is_demo',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Admin who performed the action
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Prevent deletion of audit log entries.
     */
    public function delete(): ?bool
    {
        throw new \RuntimeException(
            'AdminAuditLog entries are immutable and cannot be modified or deleted.'
        );
    }

    /**
     * Prevent force deletion of audit log entries.
     */
    public function forceDelete(): ?bool
    {
        throw new \RuntimeException(
            'AdminAuditLog entries are immutable and cannot be modified or deleted.'
        );
    }

    /**
     * Prevent updates to audit log entries.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException(
            'AdminAuditLog entries are immutable and cannot be modified or deleted.'
        );
    }

    /**
     * Allow save only when creating; block save on existing rows.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException(
                'AdminAuditLog entries are immutable and cannot be modified or deleted.'
            );
        }
        return parent::save($options);
    }
}