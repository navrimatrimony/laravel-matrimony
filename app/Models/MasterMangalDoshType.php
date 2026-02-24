<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterMangalDoshType extends Model
{
    protected $table = 'master_mangal_dosh_types';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
