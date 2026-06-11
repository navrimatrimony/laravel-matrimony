<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakRetentionArchiveRun extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_BLOCKED,
    ];

    protected $table = 'suchak_retention_archive_runs';

    protected $fillable = [
        'retention_archive_rule_id',
        'suchak_account_id',
        'run_key',
        'record_type',
        'run_status',
        'cutoff_date',
        'candidate_record_count',
        'retained_record_count',
        'archived_marker_count',
        'deleted_record_count',
        'skipped_record_count',
        'triggered_by_user_id',
        'admin_audit_log_id',
        'metrics_json',
        'run_note',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'cutoff_date' => 'date',
        'candidate_record_count' => 'integer',
        'retained_record_count' => 'integer',
        'archived_marker_count' => 'integer',
        'deleted_record_count' => 'integer',
        'skipped_record_count' => 'integer',
        'metrics_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function retentionArchiveRule(): BelongsTo
    {
        return $this->belongsTo(SuchakRetentionArchiveRule::class, 'retention_archive_rule_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak retention archive runs cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak retention archive runs cannot be deleted.');
    }
}
