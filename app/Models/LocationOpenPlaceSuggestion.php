<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Free-text / partial-hierarchy location queue (Step 4). Separate from {@see LocationSuggestion}
 * which requires full taluka-bound rows.
 */
class LocationOpenPlaceSuggestion extends Model
{
    protected $table = 'location_open_place_suggestions';

    protected $guarded = ['id'];

    /** Match types: exact, fuzzy, manual, none */
    protected $fillable = [
        'raw_input',
        'normalized_input',
        'country_id',
        'state_id',
        'district_id',
        'taluka_id',
        'resolved_city_id',
        'match_type',
        'confidence_score',
        'status',
        'usage_count',
        'suggested_by',
        'admin_reviewed_by',
        'admin_reviewed_at',
        'merged_into_suggestion_id',
    ];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'float',
            'admin_reviewed_at' => 'datetime',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function taluka(): BelongsTo
    {
        return $this->belongsTo(Taluka::class);
    }

    public function resolvedCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'resolved_city_id');
    }

    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by');
    }

    public function adminReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_reviewed_by');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_suggestion_id');
    }

    public function eligibleForAutoPromotion(int $threshold = 5): bool
    {
        return $this->status === 'pending'
            && $this->merged_into_suggestion_id === null
            && $this->usage_count >= $threshold;
    }
}
