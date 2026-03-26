<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowcaseAdminAction extends Model
{
    protected $table = 'showcase_admin_actions';

    protected $fillable = [
        'admin_user_id',
        'showcase_profile_id',
        'conversation_id',
        'action_type',
        'notes',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function showcaseProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'showcase_profile_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}

