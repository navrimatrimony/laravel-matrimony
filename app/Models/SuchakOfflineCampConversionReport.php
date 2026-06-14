<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakOfflineCampConversionReport extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    protected $table = 'suchak_offline_camp_conversion_reports';

    protected $fillable = [
        'offline_camp_id',
        'suchak_account_id',
        'source_tag',
        'report_status',
        'total_intake_links',
        'unique_intake_links',
        'possible_duplicate_links',
        'consent_pending_count',
        'customer_context_count',
        'package_assignment_count',
        'active_service_count',
        'report_note',
        'report_note_mr',
        'metrics_json',
        'generated_by_user_id',
        'generated_at',
    ];

    protected $casts = [
        'metrics_json' => 'array',
        'generated_at' => 'datetime',
    ];

    public function offlineCamp(): BelongsTo
    {
        return $this->belongsTo(SuchakOfflineCamp::class, 'offline_camp_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp conversion reports cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak offline camp conversion reports cannot be deleted.');
    }
}
