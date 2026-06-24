<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileOnboardingDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'matrimony_profile_id',
        'current_step',
        'last_completed_step',
        'draft_data',
        'completed_steps',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'draft_data' => 'array',
        'completed_steps' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }
}
