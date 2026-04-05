<?php

/**
 * Notifications / contact request UI strings.
 */
return [
    'contact_requests_access' => 'Contact Requests & Access',
    'requests_pending' => 'Requests (Pending)',
    'access_granted_active' => 'Access Granted (Active)',
    'view_profile' => 'View profile',
    'reason' => 'Reason',
    'requested' => 'Requested',
    'approve' => 'Approve',
    'reject' => 'Reject',
    'grant' => 'Grant',
    'grant_access_select_scopes_duration' => 'Grant access (select scopes and duration):',
    'scopes' => 'Scopes',
    'valid_until' => 'Valid until',
    'revoke_access' => 'Revoke access',
    'no_pending_contact_requests' => 'No pending contact requests.',
    'no_active_access' => 'No one has active access to your contact right now.',
    'confirm_reject_request' => 'Reject this request? They will not be able to send another request until the cooling period ends.',
    'confirm_revoke_access' => 'Revoke access? They will no longer see your contact.',
    'email' => 'Email',
    'phone' => 'Phone',
    'whatsapp' => 'WhatsApp',

    // Service validation messages (user-facing)
    'contact_request_disabled' => 'Contact request is currently disabled.',
    'pending_request_exists' => 'You already have a pending contact request with this member.',
    'contact_already_shared' => 'Contact details are already shared for this member.',
    'mutual_only' => 'Contact request is allowed only after the receiver accepts your interest.',
    'cooldown_not_ended' => 'You cannot send another request until the cooling period ends.',
    'daily_limit_reached' => 'Daily limit for contact requests reached. Try again tomorrow.',
    'select_at_least_one_contact_method' => 'Select at least one contact method.',
    'only_receiver_can_approve' => 'Only the receiver can approve this request.',
    'wrong_contact_request_type' => 'This action does not apply to this request.',
    'request_no_longer_pending' => 'Request is no longer pending.',
    'request_expired' => 'Request has expired.',
    'select_at_least_one_scope_to_grant' => 'Select at least one scope to grant.',
    'invalid_duration_option' => 'Invalid duration option.',
    'only_receiver_can_reject' => 'Only the receiver can reject this request.',
    'only_sender_can_cancel' => 'Only the sender can cancel this request.',
    'only_pending_can_be_cancelled' => 'Only pending requests can be cancelled.',
    'only_receiver_can_revoke' => 'Only the receiver can revoke access.',
    'access_already_revoked' => 'Access is already revoked.',
];
