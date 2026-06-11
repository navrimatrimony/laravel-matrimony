<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakBusinessExport extends Model
{
    use HasFactory;

    public const TYPE_LEDGER = 'ledger';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_REPORT = 'report';

    public const TYPES = [
        self::TYPE_LEDGER,
        self::TYPE_INVOICE,
        self::TYPE_RECEIPT,
        self::TYPE_REPORT,
    ];

    public const SCOPE_ACCOUNT_RECORDS = 'account_records';

    public const STATUS_GENERATED = 'generated';
    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_GENERATED,
        self::STATUS_BLOCKED,
    ];

    public const SENSITIVE_NOT_REQUESTED = 'not_requested';
    public const SENSITIVE_APPROVED = 'approved';
    public const SENSITIVE_BLOCKED = 'blocked';

    public const SENSITIVE_STATUSES = [
        self::SENSITIVE_NOT_REQUESTED,
        self::SENSITIVE_APPROVED,
        self::SENSITIVE_BLOCKED,
    ];

    protected $table = 'suchak_business_exports';

    protected $fillable = [
        'suchak_account_id',
        'export_key',
        'export_type',
        'export_scope',
        'export_status',
        'source_type',
        'source_id',
        'period_start',
        'period_end',
        'row_count',
        'file_name',
        'export_checksum',
        'includes_private_contact',
        'sensitive_access_status',
        'requested_by_user_id',
        'approved_by_admin_user_id',
        'admin_audit_log_id',
        'manifest_json',
        'generated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'row_count' => 'integer',
        'includes_private_contact' => 'boolean',
        'manifest_json' => 'array',
        'generated_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak business export records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak business export records cannot be deleted.');
    }
}
