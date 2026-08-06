<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureFlagAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'feature_flag_id',
        'key',
        'old_value',
        'new_value',
        'changed_by',
        'ip_address',
        'user_agent',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'old_value' => 'boolean',
        'new_value' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function featureFlag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function delete(): ?bool
    {
        throw new \RuntimeException('FeatureFlagAudit entries are immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new \RuntimeException('FeatureFlagAudit entries are immutable and cannot be deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('FeatureFlagAudit entries are immutable and cannot be updated.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException('FeatureFlagAudit entries are immutable and cannot be updated.');
        }

        return parent::save($options);
    }
}
