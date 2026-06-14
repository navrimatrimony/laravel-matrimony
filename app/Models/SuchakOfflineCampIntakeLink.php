<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakOfflineCampIntakeLink extends Model
{
    use HasFactory;

    public const STATUS_LINKED = 'linked';
    public const STATUS_CANCELLED = 'cancelled';

    public const DUPLICATE_UNIQUE = 'unique';
    public const DUPLICATE_POSSIBLE = 'possible_duplicate';
    public const DUPLICATE_UNAVAILABLE = 'unavailable';

    protected $table = 'suchak_offline_camp_intake_links';

    protected $fillable = [
        'offline_camp_id',
        'suchak_account_id',
        'source_link_id',
        'biodata_intake_id',
        'source_tag',
        'source_status_snapshot',
        'link_status',
        'duplicate_check_status',
        'privacy_safe_duplicate_hash',
        'duplicate_match_reference_hash',
        'link_note',
        'link_note_mr',
        'linked_by_user_id',
        'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    public function offlineCamp(): BelongsTo
    {
        return $this->belongsTo(SuchakOfflineCamp::class, 'offline_camp_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function sourceLink(): BelongsTo
    {
        return $this->belongsTo(SuchakBiodataIntakeLink::class, 'source_link_id');
    }

    public function biodataIntake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class);
    }

    public function linkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function packageAssignments(): HasMany
    {
        return $this->hasMany(SuchakOfflineCampPackageAssignment::class, 'offline_camp_intake_link_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp intake links cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp intake links cannot be deleted.');
    }
}
