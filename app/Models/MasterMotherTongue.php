<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterMotherTongue extends Model
{
    protected $table = 'master_mother_tongues';

    protected $fillable = ['key', 'label', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
