<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPackageTemplateStage extends Model
{
    use HasFactory;

    protected $table = 'suchak_package_template_stages';

    protected $fillable = [
        'package_template_id',
        'stage_key',
        'stage_name',
        'stage_description',
        'sort_order',
        'is_required',
        'expected_days',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_required' => 'boolean',
        'expected_days' => 'integer',
    ];

    public function packageTemplate(): BelongsTo
    {
        return $this->belongsTo(SuchakPackageTemplate::class, 'package_template_id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(SuchakPackageTemplateDeliverable::class, 'template_stage_id');
    }

    public function servicePackageStages(): HasMany
    {
        return $this->hasMany(SuchakServicePackageStage::class, 'template_stage_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak package template stages cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak package template stages cannot be deleted.');
    }
}
