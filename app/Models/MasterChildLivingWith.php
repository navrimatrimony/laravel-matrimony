<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterChildLivingWith extends Model
{
    protected $table = 'master_child_living_with';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
