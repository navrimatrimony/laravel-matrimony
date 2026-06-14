<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakRetentionArchiveRule extends Model
{
    use HasFactory;

    public const RECORD_LEDGER = 'ledger';
    public const RECORD_INVOICE = 'invoice';
    public const RECORD_RECEIPT = 'receipt';
    public const RECORD_DISPUTE = 'dispute';
    public const RECORD_REPORT = 'report';
    public const RECORD_BUSINESS_EXPORT = 'business_export';

    public const RECORD_TYPES = [
        self::RECORD_LEDGER,
        self::RECORD_INVOICE,
        self::RECORD_RECEIPT,
        self::RECORD_DISPUTE,
        self::RECORD_REPORT,
        self::RECORD_BUSINESS_EXPORT,
    ];

    public const ACTION_RETAIN_AUDITED = 'retain_audited';
    public const ACTION_LEGAL_HOLD = 'legal_hold';
    public const ACTION_ARCHIVE_MARKER_ONLY = 'archive_marker_only';

    public const ACTIONS = [
        self::ACTION_RETAIN_AUDITED,
        self::ACTION_LEGAL_HOLD,
        self::ACTION_ARCHIVE_MARKER_ONLY,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
    ];

    protected $table = 'suchak_retention_archive_rules';

    protected $fillable = [
        'rule_key',
        'rule_name',
        'rule_name_mr',
        'record_type',
        'retention_days',
        'archive_after_days',
        'archive_action',
        'rule_status',
        'requires_admin_export_approval',
        'policy_key',
        'created_by_user_id',
        'admin_audit_log_id',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'retention_days' => 'integer',
        'archive_after_days' => 'integer',
        'requires_admin_export_approval' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SuchakRetentionArchiveRun::class, 'retention_archive_rule_id')
            ->orderByDesc('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak retention archive rules cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak retention archive rules cannot be deleted.');
    }
}
