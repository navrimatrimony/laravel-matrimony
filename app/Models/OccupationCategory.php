<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OccupationCategory extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_occupation_categories';

    protected $fillable = [
        'name',
        'name_mr',
        'sort_order',
        'legacy_working_with_type_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function workingWithType(): BelongsTo
    {
        return $this->belongsTo(WorkingWithType::class, 'legacy_working_with_type_id');
    }

    public function occupations(): HasMany
    {
        return $this->hasMany(OccupationMaster::class, 'category_id');
    }

    public function localizedName(): string
    {
        return $this->localizedText('name');
    }
}
