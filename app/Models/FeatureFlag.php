<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'display_name',
        'description',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function audits(): HasMany
    {
        return $this->hasMany(FeatureFlagAudit::class);
    }

    public function latestAudit(): HasOne
    {
        return $this->hasOne(FeatureFlagAudit::class)->latestOfMany();
    }
}
