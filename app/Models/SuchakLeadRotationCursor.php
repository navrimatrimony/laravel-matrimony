<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakLeadRotationCursor extends Model
{
    use HasFactory;

    protected $table = 'suchak_lead_rotation_cursors';

    protected $fillable = [
        'rotation_bucket_key',
        'allocation_policy',
        'district_id',
        'taluka_id',
        'city_id',
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'last_allocated_suchak_account_id',
        'last_rotation_sequence',
        'last_allocated_at',
        'updated_by_admin_user_id',
    ];

    protected $casts = [
        'last_rotation_sequence' => 'integer',
        'last_allocated_at' => 'datetime',
    ];

    public function lastAllocatedSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'last_allocated_suchak_account_id');
    }

    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_admin_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak lead rotation cursor records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak lead rotation cursor records cannot be deleted.');
    }
}
