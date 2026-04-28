<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationMasterAlias extends Model
{
    protected $table = 'occupation_master_aliases';

    protected $fillable = [
        'occupation_master_id',
        'alias',
        'normalized_alias',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function occupationMaster(): BelongsTo
    {
        return $this->belongsTo(OccupationMaster::class, 'occupation_master_id');
    }
}
