<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterContactRelation extends Model
{
    protected $table = 'master_contact_relations';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
