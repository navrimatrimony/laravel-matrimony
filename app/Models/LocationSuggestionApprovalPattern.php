<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationSuggestionApprovalPattern extends Model
{
    protected $table = 'location_suggestion_approval_patterns';

    protected $guarded = ['id'];

    protected $fillable = [
        'normalized_input',
        'resolved_city_id',
        'resolved_location_id',
        'suggested_type',
        'suggested_parent_id',
        'confirmation_count',
        'last_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_confirmed_at' => 'datetime',
        ];
    }

    public function resolvedCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'resolved_city_id');
    }

    public function resolvedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'resolved_location_id');
    }

    public function suggestedParent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'suggested_parent_id');
    }
}
