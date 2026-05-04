<?php

namespace App\Models;

use App\Services\Location\LocationHierarchyValidator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Village rows in {@code addresses} ({@code type = village}).
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
            $village->type = 'village';
            if (($village->slug ?? '') === '' && filled($village->name)) {
                $village->slug = Str::slug((string) $village->name).'-v'.substr(md5((string) ($village->parent_id ?? '0')), 0, 6);
            }
            app(LocationHierarchyValidator::class)->validate($village);
        });

        parent::booted();

        static::addGlobalScope('geo_village', fn ($q) => $q->where('type', 'village'));
    }

    public function taluka(): BelongsTo
    {
        return $this->belongsTo(Taluka::class, 'parent_id');
    }
}
