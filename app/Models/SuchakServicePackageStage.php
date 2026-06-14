<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakServicePackageStage extends Model
{
    use HasFactory;

    protected $table = 'suchak_service_package_stages';

    protected $fillable = [
        'service_package_id',
        'template_stage_id',
        'stage_key',
        'stage_name',
        'stage_name_mr',
        'stage_description',
        'stage_description_mr',
        'sort_order',
        'is_required',
        'expected_days',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_required' => 'boolean',
        'expected_days' => 'integer',
    ];

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackage::class, 'service_package_id');
    }

    public function templateStage(): BelongsTo
    {
        return $this->belongsTo(SuchakPackageTemplateStage::class, 'template_stage_id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(SuchakServicePackageDeliverable::class, 'service_package_stage_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak service package stages cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak service package stages cannot be deleted.');
    }
}
