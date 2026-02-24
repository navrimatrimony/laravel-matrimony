<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Religion extends Model
{
    protected $fillable = ['key','label','is_active'];

    public function castes()
    {
        return $this->hasMany(Caste::class);
    }
}