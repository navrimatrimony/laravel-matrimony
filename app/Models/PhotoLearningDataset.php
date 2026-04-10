<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoLearningDataset extends Model
{
    protected $table = 'photo_learning_dataset';

    protected $fillable = [
        'profile_photo_id',
        'moderation_scan_json',
        'final_decision',
        'admin_id',
    ];

    protected $casts = [
        'moderation_scan_json' => 'array',
    ];

    public function profilePhoto(): BelongsTo
    {
        return $this->belongsTo(ProfilePhoto::class, 'profile_photo_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
