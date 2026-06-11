<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakOfflineCampPackageAssignment extends Model
{
    use HasFactory;

    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'suchak_offline_camp_package_assignments';

    protected $fillable = [
        'offline_camp_id',
        'offline_camp_intake_link_id',
        'suchak_account_id',
        'source_link_id',
        'customer_context_id',
        'service_package_id',
        'assignment_status',
        'assignment_note',
        'assigned_by_user_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function offlineCamp(): BelongsTo
    {
        return $this->belongsTo(SuchakOfflineCamp::class, 'offline_camp_id');
    }

    public function intakeLink(): BelongsTo
    {
        return $this->belongsTo(SuchakOfflineCampIntakeLink::class, 'offline_camp_intake_link_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function sourceLink(): BelongsTo
    {
        return $this->belongsTo(SuchakBiodataIntakeLink::class, 'source_link_id');
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(SuchakServicePackage::class, 'service_package_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp package assignments cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp package assignments cannot be deleted.');
    }
}
