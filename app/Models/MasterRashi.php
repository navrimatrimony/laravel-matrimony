<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterRashi extends Model
{
    protected $table = 'master_rashis';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
