<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterGan extends Model
{
    protected $table = 'master_gans';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
