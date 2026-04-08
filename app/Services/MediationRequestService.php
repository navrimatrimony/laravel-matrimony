<?php

namespace App\Services;

use App\Events\MediationRequestCreated;
use App\Events\MediationRequestResponded;
use App\Models\MatrimonyProfile;
use App\Models\MediationRequest;
use App\Models\User;
use App\Notifications\MediationRequestReceivedNotification;
use App\Notifications\MediationRequestResponseNotification;
use App\Services\AdminActivityNotificationGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediationRequestService
{
    public function __construct(
        protected ContactAccessService $contactAccess,
        protected ContactRevealPolicyService $contactRevealPolicy,
    ) {}

    /**
     * Viewer requests assisted matchmaking toward {@see $subjectProfile} (receiver = profile owner).
     * Does not share contact; optional paid reveal is gated until receiver responds "interested".
     *
     * @throws InvalidArgumentException
     */
    public function createFromProfile(User $sender, MatrimonyProfile $subjectProfile): MediationRequest
    {
        $senderProfile = $sender->matrimonyProfile;
        if (! $senderProfile) {
            throw new InvalidArgumentException(__('mediation.sender_needs_profile'));
        }
        if ($senderProfile->id === $subjectProfile->id) {
            throw new InvalidArgumentException(__('mediation.cannot_request_self'));
        }

        $receiver = $this->resolveProfileOwnerUser($subjectProfile);

        if ($receiver->id === $sender->id) {
            throw new InvalidArgumentException(__('mediation.cannot_request_self'));
        }

        if (MediationRequest::query()
            ->where('sender_id', $sender->id)
            ->where('receiver_id', $receiver->id)
            ->where('receiver_profile_id', $subjectProfile->id)
            ->where('status', MediationRequest::STATUS_PENDING)
            ->exists()) {
            throw new InvalidArgumentException(__('mediation.duplicate_pending'));
        }

        $visibility = DB::table('profile_visibility_settings')
            ->where('profile_id', $subjectProfile->id)
            ->first();
        if (! $this->contactRevealPolicy->allowsMediatorPath($subjectProfile, $visibility)) {
            throw new InvalidArgumentException(__('contact_access.mediator_not_available'));
        }

        $compatibilityHint = __('mediation.compatibility_hint_placeholder');

        $mediation = DB::transaction(function () use ($sender, $receiver, $senderProfile, $subjectProfile, $compatibilityHint) {
            $this->contactAccess->assertMediatorAllowed($sender);

            $row = MediationRequest::query()->create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $subjectProfile->id,
                'subject_profile_id' => $subjectProfile->id,
                'status' => MediationRequest::STATUS_PENDING,
                'meta' => [
                    'initiated_from' => 'profile_show',
                    'client' => 'web',
                    'channels' => [
                        'in_app' => true,
                        'whatsapp' => false,
                    ],
                    'matchmaking' => [
                        'compatibility_hint' => $compatibilityHint,
                        'compatibility_source' => 'placeholder',
                    ],
                ],
                'admin_notified_at' => now(),
            ]);

            $this->contactAccess->incrementMediatorUsage($sender);

            return $row;
        });

        $mediation->load(['sender.matrimonyProfile', 'receiver', 'senderProfile', 'receiverProfile']);
        if (AdminActivityNotificationGate::allowsPeerActivityNotification($mediation->sender)) {
            $receiver->notify(new MediationRequestReceivedNotification($mediation));
        }
        event(new MediationRequestCreated($mediation));

        return $mediation;
    }

    /**
     * Receiver responds. Feedback is optional for all choices; stored for ops / future AI / WhatsApp.
     *
     * @param  'interested'|'not_interested'|'need_more_info'  $response
     *
     * @throws InvalidArgumentException
     */
    public function respond(User $receiver, MediationRequest $mediation, string $response, ?string $feedback = null): MediationRequest
    {
        if ($mediation->receiver_id !== $receiver->id) {
            throw new InvalidArgumentException(__('mediation.only_receiver'));
        }
        if (! $mediation->isPending()) {
            throw new InvalidArgumentException(__('mediation.already_responded'));
        }

        $feedback = $feedback !== null ? trim($feedback) : null;
        if ($feedback === '') {
            $feedback = null;
        }
        if ($feedback !== null) {
            $feedback = Str::limit($feedback, 2000, '');
        }

        $status = match ($response) {
            'interested' => MediationRequest::STATUS_INTERESTED,
            'not_interested' => MediationRequest::STATUS_NOT_INTERESTED,
            'need_more_info' => MediationRequest::STATUS_NEED_MORE_INFO,
            default => throw new InvalidArgumentException(__('mediation.invalid_response')),
        };

        $meta = $mediation->meta ?? [];
        $meta['matchmaking'] = array_merge($meta['matchmaking'] ?? [], [
            'receiver_choice' => $response,
            'receiver_feedback' => $feedback,
            'responded_at' => now()->toIso8601String(),
        ]);

        $mediation->status = $status;
        $mediation->response_feedback = $feedback;
        $mediation->responded_at = now();
        $mediation->meta = $meta;
        $mediation->save();

        $mediation->load(['sender.matrimonyProfile', 'receiver.matrimonyProfile', 'senderProfile', 'receiverProfile']);
        if (AdminActivityNotificationGate::allowsPeerActivityNotification($receiver)) {
            $mediation->sender->notify(new MediationRequestResponseNotification($mediation));
        }
        event(new MediationRequestResponded($mediation));

        return $mediation->fresh();
    }

    /**
     * Ensures profile has a user row (same pattern as contact-requests for legacy data).
     */
    private function resolveProfileOwnerUser(MatrimonyProfile $profile): User
    {
        $receiver = $profile->user;
        if ($receiver) {
            return $receiver;
        }

        $receiver = User::firstOrCreate(
            ['email' => 'mediation-profile-'.$profile->id.'@system.local'],
            [
                'name' => $profile->full_name ?: 'Profile '.$profile->id,
                'password' => bcrypt(str()->random(32)),
            ]
        );

        if ($profile->user_id === null || (int) $profile->user_id !== (int) $receiver->id) {
            DB::table('matrimony_profiles')
                ->where('id', $profile->id)
                ->update(['user_id' => $receiver->id, 'updated_at' => now()]);
            $profile->setRelation('user', $receiver);
        }

        return $receiver;
    }
}
