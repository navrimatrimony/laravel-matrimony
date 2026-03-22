<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caste extends Model
{
    protected $fillable = ['religion_id', 'key', 'label', 'label_en', 'label_mr', 'is_active'];

    public function religion()
    {
        return $this->belongsTo(Religion::class);
    }

    public function subCastes()
    {
        return $this->hasMany(SubCaste::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CasteAlias::class);
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
