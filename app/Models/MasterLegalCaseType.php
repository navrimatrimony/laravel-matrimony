<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterLegalCaseType extends Model
{
    protected $table = 'master_legal_case_types';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
