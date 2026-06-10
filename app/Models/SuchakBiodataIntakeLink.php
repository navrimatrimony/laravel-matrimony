<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SuchakBiodataIntakeLink extends Model
{
    use HasFactory;

    public const STATUS_INTAKE_UPLOADED = 'intake_uploaded';
    public const STATUS_INTAKE_PARSED = 'intake_parsed';
    public const STATUS_REVIEW_PENDING = 'review_pending';
    public const STATUS_LINKED_TO_EXISTING_PROFILE = 'linked_to_existing_profile';
    public const STATUS_CREATED_NEW_PROFILE = 'created_new_profile';
    public const STATUS_DUPLICATE_PENDING_CONSENT = 'duplicate_pending_consent';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'suchak_biodata_intake_links';

    protected $fillable = [
        'suchak_account_id',
        'biodata_intake_id',
        'matrimony_profile_id',
        'source_status',
        'created_by_user_id',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function biodataIntake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function customerContext(): HasOne
    {
        return $this->hasOne(SuchakCustomerContext::class, 'source_link_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak biodata intake source links cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak biodata intake source links cannot be deleted.');
    }
}
