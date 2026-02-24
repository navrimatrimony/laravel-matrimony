<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterOwnershipType extends Model
{
    protected $table = 'master_ownership_types';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
