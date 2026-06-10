<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakLeadAllocationPreference extends Model
{
    use HasFactory;

    protected $table = 'suchak_lead_allocation_preferences';

    protected $fillable = [
        'suchak_account_id',
        'district_id',
        'taluka_id',
        'city_id',
        'religion_id',
        'caste_id',
        'sub_caste_id',
        'priority_weight',
        'is_active',
        'preference_note',
        'created_by_admin_user_id',
    ];

    protected $casts = [
        'priority_weight' => 'integer',
        'is_active' => 'boolean',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak lead allocation preference records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak lead allocation preference records cannot be deleted.');
    }
}
