<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakTrainingCertificate extends Model
{
    use HasFactory;

    public const STATUS_ISSUED = 'issued';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_REVOKED,
    ];

    public const SCOPE_INTERNAL = 'internal';

    public const PUBLIC_BADGE_NOT_PUBLIC = 'not_public';
    public const PUBLIC_BADGE_FUTURE_REVIEW = 'future_review';

    protected $table = 'suchak_training_certificates';

    protected $fillable = [
        'suchak_account_id',
        'certificate_code',
        'certificate_status',
        'certificate_scope',
        'public_badge_status',
        'required_module_ids_json',
        'certificate_note',
        'certificate_note_mr',
        'issued_by_admin_user_id',
        'issued_admin_audit_log_id',
        'issued_at',
        'revoked_by_admin_user_id',
        'revoked_admin_audit_log_id',
        'revoked_at',
        'revocation_note',
        'revocation_note_mr',
    ];

    protected $casts = [
        'required_module_ids_json' => 'array',
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function issuedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_admin_user_id');
    }

    public function issuedAdminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'issued_admin_audit_log_id');
    }

    public function revokedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_admin_user_id');
    }

    public function revokedAdminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'revoked_admin_audit_log_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak training certificates cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak training certificates cannot be deleted.');
    }
}
