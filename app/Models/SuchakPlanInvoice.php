<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPlanInvoice extends Model
{
    use HasFactory;

    protected $table = 'suchak_plan_invoices';

    protected $fillable = [
        'suchak_plan_payment_id',
        'invoice_number',
        'fy_label',
        'sequence_no',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SuchakPlanPayment::class, 'suchak_plan_payment_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak plan invoices cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak plan invoices cannot be deleted.');
    }
}
