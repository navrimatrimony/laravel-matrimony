<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserContactRevealLog extends Model
{
    protected $table = 'user_contact_reveal_logs';

    protected $fillable = [
        'viewer_user_id',
        'viewed_profile_id',
        'period_start',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
        ];
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }

    public function viewedProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'viewed_profile_id');
    }
}
