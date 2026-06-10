<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCustomerAgreementStage extends Model
{
    use HasFactory;

    protected $table = 'suchak_customer_agreement_stages';

    protected $fillable = [
        'customer_agreement_id',
        'service_package_stage_id',
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

    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
    }

    public function servicePackageStage(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackageStage::class, 'service_package_stage_id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(SuchakCustomerAgreementDeliverable::class, 'agreement_stage_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer agreement stages cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer agreement stages cannot be deleted.');
    }
}
