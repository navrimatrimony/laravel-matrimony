<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubCaste extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_sub_castes';

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
