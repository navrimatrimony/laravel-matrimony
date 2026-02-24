<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caste extends Model
{
    protected $fillable = ['religion_id','key','label','is_active'];

    public function religion()
    {
        return $this->belongsTo(Religion::class);
    }

    public function subCastes()
    {
        return $this->hasMany(SubCaste::class);
    }
}