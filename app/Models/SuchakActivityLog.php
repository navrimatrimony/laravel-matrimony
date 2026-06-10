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

    public const ACTION_PAYMENT_CONTEXT_RESOLVED = 'payment_context_resolved';

    public const ACTION_CUSTOMER_CONTEXT_CREATED = 'customer_context_created';

    public const ACTION_CUSTOMER_SOURCE_CLASSIFIED = 'customer_source_classified';

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

    public const ACTION_PACKAGE_TEMPLATE_CREATED = 'package_template_created';

    public const ACTION_SERVICE_PACKAGE_CREATED = 'service_package_created';

    public const ACTION_SERVICE_PACKAGE_APPROVED = 'service_package_approved';

    public const ACTION_CUSTOMER_AGREEMENT_CREATED = 'customer_agreement_created';

    public const ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED = 'customer_agreement_terms_accepted';

    public const ACTION_CUSTOMER_AGREEMENT_TERMS_BYPASSED = 'customer_agreement_terms_bypassed';

    public const ACTION_CUSTOMER_AGREEMENT_REVISED = 'customer_agreement_revised';

    public const ACTION_PAYMENT_REQUEST_CREATED = 'payment_request_created';

    public const ACTION_PAYMENT_REQUEST_SENT = 'payment_request_sent';

    public const ACTION_PAYMENT_REQUEST_OPENED = 'payment_request_opened';

    public const ACTION_PAYMENT_REQUEST_CANCELLED = 'payment_request_cancelled';

    public const ACTION_PAYMENT_REQUEST_EXPIRED = 'payment_request_expired';

    public const ACTION_CUSTOMER_PAYMENT_RECORDED = 'customer_payment_recorded';

    public const ACTION_CUSTOMER_PAYMENT_DOCUMENT_ISSUED = 'customer_payment_document_issued';

    public const ACTION_CUSTOMER_REFUND_REQUESTED = 'customer_refund_requested';

    public const ACTION_CUSTOMER_REFUND_APPROVED = 'customer_refund_approved';

    public const ACTION_CUSTOMER_REFUND_PAID = 'customer_refund_paid';

    public const ACTION_CUSTOMER_WAIVER_POSTED = 'customer_waiver_posted';

    public const ACTION_CUSTOMER_CREDIT_NOTE_ISSUED = 'customer_credit_note_issued';

    public const ACTION_CUSTOMER_PAYMENT_REVERSAL_POSTED = 'customer_payment_reversal_posted';

    public const ACTION_CUSTOMER_OVERDUE_ACTION_OPENED = 'customer_overdue_action_opened';

    public const ACTION_CUSTOMER_OVERDUE_ACTION_RESOLVED = 'customer_overdue_action_resolved';

    public const ACTION_CUSTOMER_FAMILY_MEMBER_LINKED = 'customer_family_member_linked';

    public const ACTION_CUSTOMER_FAMILY_MEMBER_REVOKED = 'customer_family_member_revoked';

    public const ACTION_CUSTOMER_PORTAL_LINK_ISSUED = 'customer_portal_link_issued';

    public const ACTION_CUSTOMER_PORTAL_LINK_OPENED = 'customer_portal_link_opened';

    public const ACTION_CUSTOMER_PORTAL_LINK_CLAIMED = 'customer_portal_link_claimed';

    public const ACTION_CUSTOMER_PORTAL_LINK_REVOKED = 'customer_portal_link_revoked';

    public const ACTION_CUSTOMER_PORTAL_LINK_EXPIRED = 'customer_portal_link_expired';

    public const ACTION_DIRECT_PAYMENT_COMPLAINT_OPENED = 'direct_payment_complaint_opened';

    public const ACTION_DIRECT_PAYMENT_EVIDENCE_ADDED = 'direct_payment_evidence_added';

    public const ACTION_PAYMENT_FEATURE_FREEZE_OPENED = 'payment_feature_freeze_opened';

    public const ACTION_PAYOUT_HOLD_OPENED = 'payout_hold_opened';

    public const ACTION_PLATFORM_PAYOUT_QUALIFIED = 'platform_payout_qualified';

    public const ACTION_PLATFORM_PAYOUT_DETAILS_UPDATED = 'platform_payout_details_updated';

    public const ACTION_PLATFORM_PAYOUT_APPROVED = 'platform_payout_approved';

    public const ACTION_PLATFORM_PAYOUT_PAID = 'platform_payout_paid';

    public const ACTION_PLATFORM_PAYOUT_REVERSED = 'platform_payout_reversed';

    public const ACTION_PLATFORM_PAYOUT_CANCELLED = 'platform_payout_cancelled';

    public const ACTION_PLATFORM_PAYOUT_SETTLEMENT_GENERATED = 'platform_payout_settlement_generated';

    public const ACTION_VISIT_SCHEDULED = 'visit_scheduled';

    public const ACTION_VISIT_COMPLETION_MARKED = 'visit_completion_marked';

    public const ACTION_VISIT_USER_CONFIRMED = 'visit_user_confirmed';

    public const ACTION_VISIT_ADMIN_CONFIRMED = 'visit_admin_confirmed';

    public const ACTION_VISIT_DISPUTED = 'visit_disputed';

    public const ACTION_VISIT_PAYOUT_QUALIFIED = 'visit_payout_qualified';

    public const ACTION_GROWTH_ATTRIBUTION_RECORDED = 'growth_attribution_recorded';

    public const ACTION_GROWTH_REWARD_RULE_CREATED = 'growth_reward_rule_created';

    public const ACTION_GROWTH_REWARD_QUALIFIED = 'growth_reward_qualified';

    public const ACTION_GROWTH_REWARD_REVERSED = 'growth_reward_reversed';

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
