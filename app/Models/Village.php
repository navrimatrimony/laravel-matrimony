<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Village extends Model
{
    protected $fillable = ['taluka_id', 'name', 'is_active'];

    public function taluka()
    {
        return $this->belongsTo(Taluka::class, 'taluka_id');
    }
}
