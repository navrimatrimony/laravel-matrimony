<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterDiet extends Model
{
    protected $table = 'master_diets';

    protected $fillable = ['key', 'label', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
