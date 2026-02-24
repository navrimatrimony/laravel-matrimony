<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterNakshatra extends Model
{
    protected $table = 'master_nakshatras';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
