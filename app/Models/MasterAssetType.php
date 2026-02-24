<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterAssetType extends Model
{
    protected $table = 'master_asset_types';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
