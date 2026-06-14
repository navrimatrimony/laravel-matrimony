<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPackageTemplateDeliverable extends Model
{
    use HasFactory;

    protected $table = 'suchak_package_template_deliverables';

    protected $fillable = [
        'package_template_id',
        'template_stage_id',
        'deliverable_key',
        'deliverable_name',
        'deliverable_name_mr',
        'deliverable_description',
        'deliverable_description_mr',
        'sort_order',
        'is_required',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_required' => 'boolean',
    ];

    public function packageTemplate(): BelongsTo
    {
        return $this->belongsTo(SuchakPackageTemplate::class, 'package_template_id');
    }

    public function templateStage(): BelongsTo
    {
        return $this->belongsTo(SuchakPackageTemplateStage::class, 'template_stage_id');
    }

    public function servicePackageDeliverables(): HasMany
    {
        return $this->hasMany(SuchakServicePackageDeliverable::class, 'template_deliverable_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak package template deliverables cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak package template deliverables cannot be deleted.');
    }
}
