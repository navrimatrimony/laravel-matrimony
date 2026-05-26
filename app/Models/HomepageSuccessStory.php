<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageSuccessStory extends Model
{
    protected $fillable = [
        'couple_names',
        'location',
        'wedding_date',
        'story_mr',
        'story_en',
        'image_path',
        'is_published',
        'is_featured',
        'consent_confirmed',
        'sort_order',
        'created_by_admin_id',
    ];

    protected $casts = [
        'wedding_date' => 'date',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'consent_confirmed' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? asset($this->image_path) : null;
    }
}
