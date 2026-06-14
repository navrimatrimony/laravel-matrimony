<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCustomerAgreementDeliverable extends Model
{
    use HasFactory;

    protected $table = 'suchak_customer_agreement_deliverables';

    protected $fillable = [
        'customer_agreement_id',
        'agreement_stage_id',
        'service_package_deliverable_id',
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

    public function customerAgreement(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreement::class, 'customer_agreement_id');
    }

    public function agreementStage(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerAgreementStage::class, 'agreement_stage_id');
    }

    public function servicePackageDeliverable(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackageDeliverable::class, 'service_package_deliverable_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer agreement deliverables cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer agreement deliverables cannot be deleted.');
    }
}
