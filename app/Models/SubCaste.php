<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubCaste extends Model
{
    protected $fillable = [
        'caste_id',
        'key',
        'label',
        'label_en',
        'label_mr',
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

    public function aliases(): HasMany
    {
        return $this->hasMany(SubCasteAlias::class, 'sub_caste_id');
    }

    public function getDisplayLabelAttribute(): string
    {
        $locale = app()->getLocale();
        if ($locale === 'mr' && $this->label_mr !== null && $this->label_mr !== '') {
            return $this->label_mr;
        }
        if ($this->label_en !== null && $this->label_en !== '') {
            return $this->label_en;
        }

        return (string) $this->label;
    }
}
