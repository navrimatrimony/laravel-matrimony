<?php

namespace App\Services;

use App\Models\ContactGrant;
use App\Models\ContactRequest;
use App\Models\Interest;
use App\Models\User;
use App\Notifications\ContactGrantRevokedNotification;
use App\Notifications\ContactRequestAcceptedNotification;
use App\Notifications\ContactRequestExpiredNotification;
use App\Notifications\ContactRequestReceivedNotification;
use App\Notifications\ContactRequestRejectedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Day-32: Contact request flow — create, approve, reject, cancel, revoke.
 * All mutations through this service; policy from config/communication.php.
 */
class ContactRequestService
{
    protected array $config;

    public function __construct()
    {
        $this->config = \App\Services\CommunicationPolicyService::getConfig();
    }

    /**
     * Check if contact request system is enabled and if mutual is required.
     */
    public function isContactRequestDisabled(): bool
    {
        return ($this->config['contact_request_mode'] ?? 'mutual_only') === 'disabled';
    }

    /**
     * Whether contact request is allowed only after mutual interest.
     */
    public function isMutualOnly(): bool
    {
        return ($this->config['contact_request_mode'] ?? 'mutual_only') === 'mutual_only';
    }

    /**
     * Mutual interest: both A→B and B→A interests exist and are accepted.
     */
    public function hasMutualInterest(User $sender, User $receiver): bool
    {
        $senderProfile = $sender->matrimonyProfile;
        $receiverProfile = $receiver->matrimonyProfile;
        if (! $senderProfile || ! $receiverProfile) {
            return false;
        }
        $a = $senderProfile->id;
        $b = $receiverProfile->id;
        $ab = Interest::where('sender_profile_id', $a)->where('receiver_profile_id', $b)->where('status', 'accepted')->exists();
        $ba = Interest::where('sender_profile_id', $b)->where('receiver_profile_id', $a)->where('status', 'accepted')->exists();
        return $ab && $ba;
    }

    /**
     * Cooldown: last rejected request from sender to receiver still in cooldown?
     */
    public function getCooldownEndsAt(User $sender, User $receiver): ?\DateTimeInterface
    {
        $row = ContactRequest::where('sender_id', $sender->id)
            ->where('receiver_id', $receiver->id)
            ->where('status', ContactRequest::STATUS_REJECTED)
            ->whereNotNull('cooldown_ends_at')
            ->where('cooldown_ends_at', '>', now())
            ->orderByDesc('cooldown_ends_at')
            ->first();
        return $row?->cooldown_ends_at;
    }

    /**
     * Count contact requests created today by sender.
     */
    public function countRequestsTodayBySender(User $sender): int
    {
        return ContactRequest::where('sender_id', $sender->id)
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * Create a contact request. Validates policy, cooldown, max per day.
     */
    public function createRequest(User $sender, User $receiver, string $reason, array $requested_scopes, ?string $otherReasonText = null): ContactRequest
    {
        if ($this->isContactRequestDisabled()) {
            throw ValidationException::withMessages(['contact_request' => __('notifications.contact_request_disabled')]);
        }

        // Prevent duplicate *outgoing* pending request from same sender→receiver.
        $openPending = ContactRequest::where('sender_id', $sender->id)
            ->where('receiver_id', $receiver->id)
            ->where('status', ContactRequest::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
        if ($openPending) {
            throw ValidationException::withMessages(['contact_request' => __('notifications.pending_request_exists')]);
        }

        // If there is an active grant for this sender→receiver, avoid duplicate requests.
        $existingGrant = $this->getEffectiveGrant($sender, $receiver);
        if ($existingGrant) {
            throw ValidationException::withMessages(['contact_request' => __('notifications.contact_already_shared')]);
        }

        if ($this->isMutualOnly() && ! $this->hasMutualInterest($sender, $receiver)) {
            $receiverProfile = $receiver->matrimonyProfile;
            $isDemoReceiver = $receiverProfile && ($receiverProfile->is_demo ?? false);
            if (! $isDemoReceiver) {
                    throw ValidationException::withMessages(['contact_request' => __('notifications.mutual_only')]);
            }
            // For demo profiles, allow contact request even without mutual interest (testing flow).
        }

        $cooldownEnds = $this->getCooldownEndsAt($sender, $receiver);
        if ($cooldownEnds) {
            throw ValidationException::withMessages([
                'contact_request' => __('notifications.cooldown_not_ended'),
                'cooldown_ends_at' => $cooldownEnds->format('Y-m-d H:i:s'),
            ]);
        }

        $maxPerDay = $this->config['max_requests_per_day_per_sender'] ?? null;
        if ($maxPerDay !== null && $this->countRequestsTodayBySender($sender) >= $maxPerDay) {
            throw ValidationException::withMessages(['contact_request' => __('notifications.daily_limit_reached')]);
        }

        $allowed = array_keys(array_filter($this->config['allowed_contact_scopes'] ?? ['email' => true, 'phone' => true, 'whatsapp' => true]));
        $requested_scopes = array_values(array_intersect($requested_scopes, $allowed));
        if (empty($requested_scopes)) {
            throw ValidationException::withMessages(['requested_scopes' => __('notifications.select_at_least_one_contact_method')]);
        }

        $pendingExpiryDays = (int) ($this->config['pending_expiry_days'] ?? 7);
        $expiresAt = now()->addDays($pendingExpiryDays);

        return DB::transaction(function () use ($sender, $receiver, $reason, $otherReasonText, $requested_scopes, $expiresAt) {
            return ContactRequest::create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'reason' => $reason,
                'other_reason_text' => $otherReasonText,
                'requested_scopes' => $requested_scopes,
                'status' => ContactRequest::STATUS_PENDING,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    /**
     * Approve request: create grant, set request status accepted.
     */
    public function approve(ContactRequest $request, User $receiver, array $grantedScopes, string $durationKey): ContactGrant
    {
        if ($request->receiver_id !== $receiver->id) {
            throw ValidationException::withMessages(['request' => __('notifications.only_receiver_can_approve')]);
        }
        if ($request->status !== ContactRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => __('notifications.request_no_longer_pending')]);
        }
        if ($request->expires_at && $request->expires_at->isPast()) {
            $request->update(['status' => ContactRequest::STATUS_EXPIRED]);
            throw ValidationException::withMessages(['request' => __('notifications.request_expired')]);
        }

        $allowed = array_keys(array_filter($this->config['allowed_contact_scopes'] ?? []));
        $grantedScopes = array_values(array_intersect($grantedScopes, $request->requested_scopes));
        $grantedScopes = array_values(array_intersect($grantedScopes, $allowed));
        if (empty($grantedScopes)) {
            throw ValidationException::withMessages(['granted_scopes' => __('notifications.select_at_least_one_scope_to_grant')]);
        }

        $options = $this->config['grant_duration_options'] ?? ['approve_once' => true, 'approve_7_days' => true, 'approve_30_days' => true];
        if (empty($options[$durationKey])) {
            throw ValidationException::withMessages(['duration' => __('notifications.invalid_duration_option')]);
        }

        $validUntil = match ($durationKey) {
            'approve_once' => now()->addDay(),
            'approve_7_days' => now()->addDays(7),
            'approve_30_days' => now()->addDays(30),
            default => now()->addDay(),
        };

        $grant = DB::transaction(function () use ($request, $grantedScopes, $validUntil) {
            $request->update(['status' => ContactRequest::STATUS_ACCEPTED]);
            return ContactGrant::create([
                'contact_request_id' => $request->id,
                'granted_scopes' => $grantedScopes,
                'valid_until' => $validUntil,
            ]);
        });
        $this->audit($request, $grant, 'approved', $request->receiver_id, ['granted_scopes' => $grantedScopes]);
        $request->sender->notify(new ContactRequestAcceptedNotification($grant));
        return $grant;
    }

    /**
     * Reject request: set status rejected, cooldown_ends_at.
     */
    public function reject(ContactRequest $request, User $receiver): void
    {
        if ($request->receiver_id !== $receiver->id) {
            throw ValidationException::withMessages(['request' => __('notifications.only_receiver_can_reject')]);
        }
        if ($request->status !== ContactRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => __('notifications.request_no_longer_pending')]);
        }

        $cooldownDays = (int) ($this->config['reject_cooldown_days'] ?? 90);
        $cooldownEndsAt = now()->addDays($cooldownDays);

        $request->update([
            'status' => ContactRequest::STATUS_REJECTED,
            'cooldown_ends_at' => $cooldownEndsAt,
        ]);
        $this->audit($request, null, 'rejected', $receiver->id, ['cooldown_ends_at' => $cooldownEndsAt->toIso8601String()]);
        $request->sender->notify(new ContactRequestRejectedNotification($request));
    }

    /**
     * Cancel pending request (sender only).
     */
    public function cancel(ContactRequest $request, User $sender): void
    {
        if ($request->sender_id !== $sender->id) {
            throw ValidationException::withMessages(['request' => __('notifications.only_sender_can_cancel')]);
        }
        if ($request->status !== ContactRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => __('notifications.only_pending_can_be_cancelled')]);
        }
        $request->update(['status' => ContactRequest::STATUS_CANCELLED]);
    }

    /**
     * Revoke grant (receiver only).
     */
    public function revokeGrant(ContactGrant $grant, User $receiver): void
    {
        $request = $grant->contactRequest;
        if ($request->receiver_id !== $receiver->id) {
            throw ValidationException::withMessages(['grant' => __('notifications.only_receiver_can_revoke')]);
        }
        if ($grant->revoked_at) {
            throw ValidationException::withMessages(['grant' => __('notifications.access_already_revoked')]);
        }
        $grant->update([
            'revoked_at' => now(),
            'revoked_by' => $receiver->id,
        ]);
        $request->update(['status' => ContactRequest::STATUS_REVOKED]);
        $this->audit($request, $grant, 'revoked', $receiver->id, []);
        $request->sender->notify(new ContactGrantRevokedNotification($grant));
    }

    /**
     * Get effective (valid, non-revoked) grant for sender→receiver, if any.
     */
    public function getEffectiveGrant(User $sender, User $receiver): ?ContactGrant
    {
        $request = ContactRequest::where('sender_id', $sender->id)
            ->where('receiver_id', $receiver->id)
            ->where('status', ContactRequest::STATUS_ACCEPTED)
            ->first();
        if (! $request) {
            return null;
        }
        $grant = $request->grant;
        if (! $grant || ! $grant->isValid()) {
            return null;
        }
        return $grant;
    }

    /**
     * Get current request/grant state for viewer (sender) viewing profile owner (receiver).
     * Returns: 'none' | 'pending' | 'accepted' | 'rejected' | 'expired' | 'revoked' | 'cancelled'
     * and optional request, grant, cooldown_ends_at.
     */
    public function getSenderState(User $sender, User $receiver): array
    {
        $request = ContactRequest::where('sender_id', $sender->id)
            ->where('receiver_id', $receiver->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $request) {
            return ['state' => 'none', 'request' => null, 'grant' => null, 'cooldown_ends_at' => null];
        }

        $cooldownEndsAt = $request->isRejected() ? $request->cooldown_ends_at : null;

        if ($request->isPending()) {
            if ($request->expires_at && $request->expires_at->isPast()) {
                $request->update(['status' => ContactRequest::STATUS_EXPIRED]);
                $this->audit($request, null, 'expired', null, []);
                $request->sender->notify(new ContactRequestExpiredNotification($request));
                return ['state' => 'expired', 'request' => $request->fresh(), 'grant' => null, 'cooldown_ends_at' => null];
            }
            return ['state' => 'pending', 'request' => $request, 'grant' => null, 'cooldown_ends_at' => null];
        }

        if ($request->isAccepted()) {
            $grant = $request->grant;
            if (! $grant || ! $grant->isValid()) {
                return ['state' => 'revoked', 'request' => $request, 'grant' => $grant, 'cooldown_ends_at' => null];
            }
            return ['state' => 'accepted', 'request' => $request, 'grant' => $grant, 'cooldown_ends_at' => null];
        }

        if ($request->isRejected()) {
            return ['state' => 'rejected', 'request' => $request, 'grant' => null, 'cooldown_ends_at' => $cooldownEndsAt];
        }
        if ($request->isExpired() || $request->isCancelled() || $request->isRevoked()) {
            return ['state' => $request->status, 'request' => $request, 'grant' => null, 'cooldown_ends_at' => null];
        }

        return ['state' => 'none', 'request' => $request, 'grant' => null, 'cooldown_ends_at' => null];
    }

    /**
     * Day-32 Step 7: Write audit log entry for contact request events.
     */
    private function audit(ContactRequest $request, ?ContactGrant $grant, string $action, ?int $actorUserId, array $details): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('contact_request_audit_log')) {
            return;
        }
        DB::table('contact_request_audit_log')->insert([
            'contact_request_id' => $request->id,
            'contact_grant_id' => $grant?->id,
            'user_id' => $actorUserId,
            'action' => $action,
            'details' => json_encode($details),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
