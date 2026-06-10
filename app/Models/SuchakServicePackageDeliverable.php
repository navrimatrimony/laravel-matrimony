<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakServicePackageDeliverable extends Model
{
    use HasFactory;

    protected $table = 'suchak_service_package_deliverables';

    protected $fillable = [
        'service_package_id',
        'service_package_stage_id',
        'template_deliverable_id',
        'deliverable_key',
        'deliverable_name',
        'deliverable_description',
        'sort_order',
        'is_required',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_required' => 'boolean',
    ];

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackage::class, 'service_package_id');
    }

    public function servicePackageStage(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackageStage::class, 'service_package_stage_id');
    }

    public function templateDeliverable(): BelongsTo
    {
        return $this->belongsTo(SuchakPackageTemplateDeliverable::class, 'template_deliverable_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak service package deliverables cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak service package deliverables cannot be deleted.');
    }
}
