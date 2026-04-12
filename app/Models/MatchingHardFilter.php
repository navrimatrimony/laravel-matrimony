<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchingHardFilter extends Model
{
    public const MODE_OFF = 'off';

    public const MODE_PREFERRED = 'preferred';

    public const MODE_STRICT = 'strict';

    protected $fillable = [
        'filter_key',
        'mode',
        'preferred_penalty_points',
    ];

    protected $casts = [
        'preferred_penalty_points' => 'integer',
    ];
}
