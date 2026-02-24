<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterAddressType extends Model
{
    protected $table = 'master_address_types';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
