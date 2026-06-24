<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPreferenceMetadata extends Model
{
    protected $table = 'partner_preference_metadata';

    protected $fillable = [
        'matrimony_profile_id',
        'source',
        'strictness_json',
        'generated_from',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'strictness_json' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'matrimony_profile_id');
    }
}
