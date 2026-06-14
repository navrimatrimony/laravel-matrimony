<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Village rows in {@code addresses} ({@code hierarchy = village}).
 * Legacy {@code taluka_id} maps to {@code parent_id}.
 *
 * @deprecated Prefer {@see Location}.
 */
class Village extends Location
{
    /** @return list<string> */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['taluka_id']);
    }

    public function getTalukaIdAttribute(): ?int
    {
        return isset($this->attributes['parent_id']) ? (int) $this->attributes['parent_id'] : null;
    }

    public function setTalukaIdAttribute(mixed $value): void
    {
        $this->attributes['parent_id'] = $value;
    }

    protected static function booted(): void
    {
        static::saving(function (Village $village): void {
            if (isset($village->attributes['taluka_id'])) {
                $village->parent_id = $village->attributes['taluka_id'];
                unset($village->attributes['taluka_id']);
            }
            $village->hierarchy = 'village';
            if (($village->slug ?? '') === '' && filled($village->name)) {
                $village->slug = static::uniqueSlugForHierarchy($village->parent_id ? (int) $village->parent_id : null, 'village', (string) $village->name, $village->id ? (int) $village->id : null);
            }
            app(LocationHierarchyValidator::class)->validate($village);
        });

        parent::booted();

        static::addGlobalScope('geo_village', fn ($q) => $q->where('hierarchy', 'village'));
    }

    public function taluka(): BelongsTo
    {
        return $this->belongsTo(Taluka::class, 'parent_id');
    }
}
