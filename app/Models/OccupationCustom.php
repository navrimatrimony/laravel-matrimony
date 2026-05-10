<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationCustom extends Model
{
    protected $table = 'master_occupation_custom';

    protected $fillable = [
        'raw_name',
        'normalized_name',
        'user_id',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
