<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCaste extends Model
{
    protected $fillable = [
        'caste_id',
        'key',
        'label',
        'is_active',
        'status',
        'created_by_user_id',
        'approved_by_admin_id',
    ];

    public function caste()
    {
        return $this->belongsTo(Caste::class);
    }

    public function createdByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id');
    }

    public function approvedByAdmin()
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by_admin_id');
    }
}