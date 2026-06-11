<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakScheduledJobRun extends Model
{
    use HasFactory;

    public const JOB_OVERDUE_PAYMENTS = 'overdue_payments';
    public const JOB_PAYOUT_CYCLES = 'payout_cycles';
    public const JOB_REWARD_QUALIFICATION = 'reward_qualification';
    public const JOB_CONSENT_EXPIRY = 'consent_expiry';
    public const JOB_QR_EXPIRY = 'qr_expiry';
    public const JOB_FOLLOW_UP_REMINDERS = 'follow_up_reminders';
    public const JOB_MONTHLY_REPORTS = 'monthly_reports';
    public const JOB_LOYALTY_RECALCULATION = 'loyalty_recalculation';

    public const JOBS = [
        self::JOB_OVERDUE_PAYMENTS,
        self::JOB_PAYOUT_CYCLES,
        self::JOB_REWARD_QUALIFICATION,
        self::JOB_CONSENT_EXPIRY,
        self::JOB_QR_EXPIRY,
        self::JOB_FOLLOW_UP_REMINDERS,
        self::JOB_MONTHLY_REPORTS,
        self::JOB_LOYALTY_RECALCULATION,
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_FAILED,
    ];

    public const TRIGGER_SYSTEM = 'system';
    public const TRIGGER_ADMIN = 'admin';

    public const TRIGGERS = [
        self::TRIGGER_SYSTEM,
        self::TRIGGER_ADMIN,
    ];

    protected $table = 'suchak_scheduled_job_runs';

    protected $fillable = [
        'run_key',
        'job_key',
        'job_status',
        'triggered_by',
        'triggered_by_user_id',
        'admin_audit_log_id',
        'account_scope_id',
        'run_for_date',
        'run_month',
        'metrics_json',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'run_for_date' => 'date',
        'metrics_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function accountScope(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'account_scope_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak scheduled job runs cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak scheduled job runs cannot be deleted.');
    }
}
