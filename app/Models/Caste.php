<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caste extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_castes';

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
        // label_en is the preferred English column; label is the legacy one still
        // populated on older rows, so it stays as the last fallback.
                // No `label_en` in the chain. It is a byte-identical copy of `label`
        // in all 666 rows across master_religions / master_castes /
        // master_sub_castes, so reading it adds a second answer to the same
        // question and nothing else. Leaving it unread is what makes the column
        // droppable later.
        return $this->localizedText('label');
    }
}
