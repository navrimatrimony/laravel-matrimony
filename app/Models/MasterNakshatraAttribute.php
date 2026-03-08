<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nakshatra-level attributes: gan_id, nadi_id, yoni_id.
 * One row per nakshatra. Used for horoscope dependency only.
 */
class MasterNakshatraAttribute extends Model
{
    protected $table = 'master_nakshatra_attributes';

    protected $fillable = ['nakshatra_id', 'gan_id', 'nadi_id', 'yoni_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function nakshatra()
    {
        return $this->belongsTo(MasterNakshatra::class, 'nakshatra_id');
    }

    public function gan()
    {
        return $this->belongsTo(MasterGan::class, 'gan_id');
    }

    public function nadi()
    {
        return $this->belongsTo(MasterNadi::class, 'nadi_id');
    }

    public function yoni()
    {
        return $this->belongsTo(MasterYoni::class, 'yoni_id');
    }
}
