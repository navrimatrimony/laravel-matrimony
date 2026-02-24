<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterNadi extends Model
{
    protected $table = 'master_nadis';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
