<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterMarriageTypePreference extends Model
{
    protected $table = 'master_marriage_type_preferences';

    protected $fillable = ['key', 'label', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
