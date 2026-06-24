<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileOnboardingMasterSuggestion extends Model
{
    protected $table = 'mobile_onboarding_master_suggestions';

    protected $fillable = [
        'type',
        'label',
        'category_id',
        'working_with_id',
        'notes',
        'status',
        'suggested_by_user_id',
    ];

    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by_user_id');
    }
}
