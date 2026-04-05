<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDailyPhotoProfileView extends Model
{
    protected $table = 'user_daily_photo_profile_views';

    protected $fillable = [
        'user_id',
        'viewed_profile_id',
        'viewed_on',
    ];

    protected function casts(): array
    {
        return [
            'viewed_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function viewedProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'viewed_profile_id');
    }
}
