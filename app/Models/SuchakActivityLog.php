<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakActivityLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SUCHAK = 'suchak';

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTION_SUCHAK_ONBOARDING_REQUESTED = 'suchak_onboarding_requested';

    public const ACTION_SOURCE_LINK_CREATED = 'source_link_created';

    public const ACTION_REPRESENTATION_CREATED = 'representation_created';

    public const ACTION_REPRESENTATION_STATUS_CHANGED = 'representation_status_changed';

    public const ACTION_REPRESENTATION_CANDIDATE_DEACTIVATED = 'representation_candidate_deactivated';

    public const ACTION_CONSENT_REQUESTED = 'consent_requested';

    public const ACTION_CONSENT_OTP_SENT = 'consent_otp_sent';

    public const ACTION_CONSENT_VERIFIED = 'consent_verified';

    public const ACTION_CONSENT_RENEWED = 'consent_renewed';

    public const ACTION_CONSENT_REVOKED = 'consent_revoked';

    public const ACTION_PDF_GENERATED = 'pdf_generated';

    public const ACTION_PDF_DOWNLOADED = 'pdf_downloaded';

    public const ACTION_PDF_SHARED = 'pdf_shared';

    public const ACTION_QR_GENERATED = 'qr_generated';

    public const ACTION_QR_SCANNED = 'qr_scanned';

    public const ACTION_QR_REVOKED = 'qr_revoked';

    public const ACTION_PUBLIC_CONTACT_ROUTED = 'public_contact_routed';

    public const ACTION_USER_REQUEST_CREATED = 'user_request_created';

    public const ACTION_PIPELINE_STATUS_CHANGED = 'pipeline_status_changed';

    public const ACTION_COLLABORATION_REQUEST_CREATED = 'collaboration_request_created';

    public const ACTION_COLLABORATION_REQUEST_ACCEPTED = 'collaboration_request_accepted';

    public const ACTION_COLLABORATION_REQUEST_REJECTED = 'collaboration_request_rejected';

    public const ACTION_COLLABORATION_REQUEST_EXPIRED = 'collaboration_request_expired';

    public const ACTION_COMMISSION_AGREEMENT_UPDATED = 'commission_agreement_updated';

    public const ACTION_CRM_NOTE_ADDED = 'crm_note_added';

    public const ACTION_LEDGER_ENTRY_CREATED = 'ledger_entry_created';

    public const ACTION_DISPUTE_OPENED = 'dispute_opened';

    public const ACTION_DISPUTE_STATUS_CHANGED = 'dispute_status_changed';

    public const ACTION_REPRESENTATION_REVOKED = 'representation_revoked';

    public const ACTION_PROFILE_UPDATE_SUGGESTION_CREATED = 'profile_update_suggestion_created';

    public const ACTION_PROFILE_UPDATE_SUGGESTION_STATUS_CHANGED = 'profile_update_suggestion_status_changed';

    public const ACTION_PROFILE_UPDATE_SUGGESTION_APPLIED = 'profile_update_suggestion_applied';

    public const ACTION_BILLING_LIMIT_CHANGED = 'billing_limit_changed';

    public const ACTION_PLAN_PAYMENT_INITIATED = 'plan_payment_initiated';

    public const ACTION_PLAN_PAYMENT_COMPLETED = 'plan_payment_completed';

    public const ACTION_PLAN_PAYMENT_FAILED = 'plan_payment_failed';

    public const ACTION_PLAN_INVOICE_CREATED = 'plan_invoice_created';

    public const ACTION_ADMIN_AUDIT_LINKED = 'admin_audit_linked';

    protected $table = 'suchak_activity_logs';

    protected $fillable = [
        'suchak_account_id',
        'actor_user_id',
        'actor_type',
        'action_type',
        'target_type',
        'target_id',
        'matrimony_profile_id',
        'admin_audit_log_id',
        'ip_address',
        'user_agent',
        'metadata_json',
        'occurred_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('SuchakActivityLog entries are immutable and cannot be modified or deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('SuchakActivityLog entries are immutable and cannot be modified or deleted.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('SuchakActivityLog entries are immutable and cannot be modified or deleted.');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('SuchakActivityLog entries are immutable and cannot be modified or deleted.');
        }

        return parent::save($options);
    }
}
