<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log. Updates and deletes are forbidden by Law 9.
 */
class FieldValueHistory extends Model
{
    protected $table = 'field_value_history';

    protected $fillable = [
        'profile_id',
        'field_key',
        'field_type',
        'old_value',
        'new_value',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public $timestamps = true;

    /**
     * Prevent deletion of history rows.
     */
    public function delete(): ?bool
    {
        throw new \RuntimeException(
            'FieldValueHistory records are immutable and cannot be deleted.'
        );
    }

    /**
     * Prevent updates to history rows.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException(
            'FieldValueHistory records are immutable and cannot be updated.'
        );
    }

    /**
     * Allow save only when creating; block save on existing rows.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException(
                'FieldValueHistory records are append-only.'
            );
        }
        return parent::save($options);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
