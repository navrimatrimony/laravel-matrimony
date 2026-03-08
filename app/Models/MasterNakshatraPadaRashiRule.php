<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rule: nakshatra_id + charan (1..4) -> rashi_id.
 * Used for horoscope dependency only; no DOB/API.
 */
class MasterNakshatraPadaRashiRule extends Model
{
    protected $table = 'master_nakshatra_pada_rashi_rules';

    protected $fillable = ['nakshatra_id', 'charan', 'rashi_id', 'is_active'];

    protected $casts = [
        'charan' => 'integer',
        'is_active' => 'boolean',
    ];

    public function nakshatra()
    {
        return $this->belongsTo(MasterNakshatra::class, 'nakshatra_id');
    }

    public function rashi()
    {
        return $this->belongsTo(MasterRashi::class, 'rashi_id');
    }
}
