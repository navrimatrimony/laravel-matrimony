<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterDrinkingStatus extends Model
{
    protected $table = 'master_drinking_statuses';

    protected $fillable = ['key', 'label', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];
}
