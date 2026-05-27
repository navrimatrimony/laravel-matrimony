<?php

namespace App\Services;

use App\Events\MediationRequestCreated;
use App\Events\MediationRequestResponded;
use App\Models\AdminSetting;
use App\Models\MatrimonyProfile;
use App\Models\MediationRequest;
use App\Models\User;
use App\Notifications\MediationRequestReceivedNotification;
use App\Notifications\MediationRequestResponseNotification;
use App\Support\SafeNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediationRequestService
{
    private const FIRST_REMINDER_AFTER_HOURS = 24;

    private const EXPIRE_AFTER_HOURS = 72;

    public const SETTING_ENABLED = 'whatsapp_response_enabled';

    public const SETTING_CHANNEL_MODE = 'whatsapp_response_channel_mode';

    public const SETTING_FIRST_REMINDER_HOURS = 'whatsapp_response_first_reminder_hours';

    public const SETTING_EXPIRE_HOURS = 'whatsapp_response_expire_hours';

    public const SETTING_ALLOW_MANUAL_SEND = 'whatsapp_response_allow_manual_send';

    public const SETTING_ALLOW_MANUAL_REMINDER = 'whatsapp_response_allow_manual_reminder';

    public const SETTING_PHOTO_IN_SUMMARY = 'whatsapp_response_photo_in_summary';

    public const SETTING_PROFILE_LINK_ENABLED = 'whatsapp_response_profile_link_enabled';

    public const SETTING_REQUEST_COOLDOWN_DAYS = 'whatsapp_response_request_cooldown_days';

    private const CHANNEL_MODES = [
        MediationRequest::CHANNEL_IN_APP_ONLY,
        MediationRequest::CHANNEL_MANUAL_SIMULATION,
        MediationRequest::CHANNEL_WHATSAPP_API,
        MediationRequest::CHANNEL_WHATSAPP_API_WITH_IN_APP_FALLBACK,
    ];

    private const DECLINE_REASONS = [
        'age_mismatch',
        'education_mismatch',
        'location_mismatch',
        'job_income_mismatch',
        'caste_subcaste_mismatch',
        'horoscope_mismatch',
        'talks_in_progress',
        'marriage_fixed',
        'other',
    ];

    private const NEXT_ACTIONS = [
        'share_my_number',
        'view_their_number',
        'app_chat',
        'office_contact',
    ];

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
        if (! $this->isEnabled()) {
            throw new InvalidArgumentException(__('contact_access.whatsapp_response_unavailable'));
        }

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

        $visibility = DB::table('profile_visibility_settings')
            ->where('profile_id', $subjectProfile->id)
            ->first();
        if (! $this->contactRevealPolicy->allowsMediatorPath($subjectProfile, $visibility)) {
            throw new InvalidArgumentException(__('contact_access.mediator_not_available'));
        }

        $compatibilityHint = __('mediation.compatibility_hint_placeholder');

        $settings = $this->settings();

        $mediation = DB::transaction(function () use ($sender, $receiver, $senderProfile, $subjectProfile, $compatibilityHint, $settings) {
            $this->assertNoActiveDuplicateMediation($sender, $subjectProfile, $settings['request_cooldown_days']);

            $this->contactAccess->assertMediatorAllowed($sender);
            $now = now();
            $cooldownEndsAt = $settings['request_cooldown_days'] > 0
                ? $now->copy()->addDays($settings['request_cooldown_days'])
                : null;

            $row = MediationRequest::query()->create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $subjectProfile->id,
                'subject_profile_id' => $subjectProfile->id,
                'status' => MediationRequest::STATUS_PENDING,
                'channel_mode' => $settings['channel_mode'],
                'delivery_status' => MediationRequest::DELIVERY_QUEUED,
                'send_due_at' => $now,
                'first_reminder_due_at' => $now->copy()->addHours($settings['first_reminder_hours']),
                'expires_at' => $now->copy()->addHours($settings['expire_hours']),
                'cooldown_ends_at' => $cooldownEndsAt,
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
            SafeNotifier::notify($receiver, new MediationRequestReceivedNotification($mediation));
        }
        event(new MediationRequestCreated($mediation));

        return $mediation;
    }

    /**
     * Receiver responds. Feedback is optional for all choices; stored for ops / future AI / WhatsApp.
     *
     * @param  'interested'|'not_interested'|'need_more_info'|'decide_later'|'talks_in_progress'  $response
     * @param  array{decline_reason?: ?string, decline_reason_note?: ?string, next_action?: ?string}  $details
     *
     * @throws InvalidArgumentException
     */
    public function respond(User $receiver, MediationRequest $mediation, string $response, ?string $feedback = null, array $details = []): MediationRequest
    {
        if ($mediation->receiver_id !== $receiver->id) {
            throw new InvalidArgumentException(__('mediation.only_receiver'));
        }
        $this->expireIfDue($mediation);
        $mediation->refresh();
        if (! $mediation->isPending()) {
            throw new InvalidArgumentException(__('mediation.already_responded'));
        }
        if ($mediation->isDeliveryExpired()) {
            throw new InvalidArgumentException(__('mediation.request_expired'));
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
            'need_more_info', 'decide_later' => MediationRequest::STATUS_NEED_MORE_INFO,
            'talks_in_progress' => MediationRequest::STATUS_NOT_INTERESTED,
            default => throw new InvalidArgumentException(__('mediation.invalid_response')),
        };

        $declineReason = in_array($response, ['not_interested', 'talks_in_progress'], true)
            ? $this->allowedDetail($details['decline_reason'] ?? null, self::DECLINE_REASONS)
            : null;
        $declineReasonNote = $declineReason === 'other'
            ? $this->cleanDetail($details['decline_reason_note'] ?? null, 500)
            : null;
        $nextAction = $response === 'interested'
            ? $this->allowedDetail($details['next_action'] ?? null, self::NEXT_ACTIONS)
            : null;

        $meta = $mediation->meta ?? [];
        $matchmakingMeta = [
            'receiver_choice' => $response,
            'receiver_feedback' => $feedback,
            'responded_at' => now()->toIso8601String(),
        ];

        if ($declineReason !== null) {
            $matchmakingMeta['receiver_decline_reason'] = $declineReason;
        }
        if ($declineReasonNote !== null) {
            $matchmakingMeta['receiver_decline_reason_note'] = $declineReasonNote;
        }
        if ($nextAction !== null) {
            $matchmakingMeta['receiver_next_action'] = $nextAction;
        }

        $meta['matchmaking'] = array_merge($meta['matchmaking'] ?? [], $matchmakingMeta);

        $mediation->status = $status;
        $mediation->delivery_status = MediationRequest::DELIVERY_RESPONDED;
        $mediation->response_feedback = $feedback;
        $mediation->responded_at = now();
        $mediation->meta = $meta;
        $mediation->save();

        $mediation->load(['sender.matrimonyProfile', 'receiver.matrimonyProfile', 'senderProfile', 'receiverProfile']);
        if (AdminActivityNotificationGate::allowsPeerActivityNotification($receiver)) {
            SafeNotifier::notify($mediation->sender, new MediationRequestResponseNotification($mediation));
        }
        event(new MediationRequestResponded($mediation));

        return $mediation->fresh();
    }

    /**
     * One recent WhatsApp Response thread per sender → target profile.
     * Refreshes expiry on existing rows (under lock) so a stale pending row does not block forever.
     *
     * @throws InvalidArgumentException
     */
    private function assertNoActiveDuplicateMediation(User $sender, MatrimonyProfile $subjectProfile, int $cooldownDays): void
    {
        $candidates = MediationRequest::query()
            ->where('sender_id', $sender->id)
            ->where('receiver_profile_id', $subjectProfile->id)
            ->orderByDesc('id')
            ->limit(30)
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $existing) {
            $this->expireIfDue($existing);
        }

        foreach ($candidates as $existing) {
            $existing->refresh();
            if ($existing->cooldown_ends_at !== null && $existing->cooldown_ends_at->isFuture()) {
                throw new InvalidArgumentException(__('mediation.duplicate_cooldown', [
                    'date' => $existing->cooldown_ends_at->timezone(config('app.timezone'))->format('M j, Y'),
                ]));
            }

            if ($cooldownDays > 0 && $existing->created_at !== null && $existing->created_at->copy()->addDays($cooldownDays)->isFuture()) {
                throw new InvalidArgumentException(__('mediation.duplicate_cooldown', [
                    'date' => $existing->created_at->copy()->addDays($cooldownDays)->timezone(config('app.timezone'))->format('M j, Y'),
                ]));
            }

            if (! $existing->isPending()) {
                continue;
            }
            if ($existing->isDeliveryExpired()) {
                continue;
            }
            if ($existing->delivery_status === MediationRequest::DELIVERY_CANCELLED) {
                continue;
            }

            throw new InvalidArgumentException(__('mediation.duplicate_pending'));
        }
    }

    public function markAsSent(MediationRequest $mediation): MediationRequest
    {
        if ($mediation->hasResponded() || $mediation->isDeliveryExpired()) {
            return $mediation->fresh();
        }

        $mediation->delivery_status = MediationRequest::DELIVERY_SENT;
        $mediation->sent_at = now();
        $mediation->delivery_attempts = (int) $mediation->delivery_attempts + 1;
        $mediation->last_delivery_error = null;
        $mediation->save();

        return $mediation->fresh();
    }

    public function expire(MediationRequest $mediation): MediationRequest
    {
        if ($mediation->hasResponded()) {
            return $mediation->fresh();
        }

        $mediation->delivery_status = MediationRequest::DELIVERY_EXPIRED;
        $mediation->expired_at = now();
        $mediation->save();

        return $mediation->fresh();
    }

    public function markReminderSent(MediationRequest $mediation): MediationRequest
    {
        if ($mediation->hasResponded() || $mediation->isDeliveryExpired()) {
            return $mediation->fresh();
        }

        $mediation->delivery_status = MediationRequest::DELIVERY_REMINDER_SENT;
        $mediation->first_reminder_sent_at = now();
        $mediation->delivery_attempts = (int) $mediation->delivery_attempts + 1;
        $mediation->last_delivery_error = null;
        $mediation->save();

        return $mediation->fresh();
    }

    public function expireIfDue(MediationRequest $mediation, ?Carbon $now = null): bool
    {
        $now ??= now();
        if ($mediation->hasResponded() || $mediation->expired_at !== null) {
            return false;
        }
        if ($mediation->expires_at === null || $mediation->expires_at->isFuture()) {
            return false;
        }

        $mediation->delivery_status = MediationRequest::DELIVERY_EXPIRED;
        $mediation->expired_at = $now;
        $mediation->save();

        return true;
    }

    public function markReminderDueIfDue(MediationRequest $mediation): bool
    {
        if (! $mediation->isReminderDue()) {
            return false;
        }

        $mediation->delivery_status = MediationRequest::DELIVERY_REMINDER_DUE;
        $mediation->save();

        return true;
    }

    public function cancel(MediationRequest $mediation): MediationRequest
    {
        if ($mediation->hasResponded() || $mediation->isDeliveryExpired()) {
            return $mediation->fresh();
        }

        $mediation->delivery_status = MediationRequest::DELIVERY_CANCELLED;
        $mediation->save();

        return $mediation->fresh();
    }

    /**
     * @return array{expired:int, reminder_due:int}
     */
    public function updateDuePipelineStates(int $limit = 200): array
    {
        $expired = 0;
        $reminderDue = 0;

        MediationRequest::query()
            ->where('status', MediationRequest::STATUS_PENDING)
            ->whereNull('responded_at')
            ->whereNull('expired_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get()
            ->each(function (MediationRequest $request) use (&$expired): void {
                if ($this->expireIfDue($request)) {
                    $expired++;
                }
            });

        MediationRequest::query()
            ->where('status', MediationRequest::STATUS_PENDING)
            ->whereNull('responded_at')
            ->whereNull('expired_at')
            ->whereNull('first_reminder_sent_at')
            ->whereNotNull('first_reminder_due_at')
            ->where('first_reminder_due_at', '<=', now())
            ->limit($limit)
            ->get()
            ->each(function (MediationRequest $request) use (&$reminderDue): void {
                if ($this->markReminderDueIfDue($request)) {
                    $reminderDue++;
                }
            });

        return ['expired' => $expired, 'reminder_due' => $reminderDue];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     channel_mode: string,
     *     first_reminder_hours: int,
     *     expire_hours: int,
     *     allow_manual_send: bool,
     *     allow_manual_reminder: bool,
     *     photo_in_summary: bool,
     *     profile_link_enabled: bool,
     *     request_cooldown_days: int
     * }
     */
    public function settings(): array
    {
        $channelMode = (string) AdminSetting::getValue(self::SETTING_CHANNEL_MODE, MediationRequest::CHANNEL_MANUAL_SIMULATION);
        if (! in_array($channelMode, self::CHANNEL_MODES, true)) {
            $channelMode = MediationRequest::CHANNEL_MANUAL_SIMULATION;
        }

        return [
            'enabled' => AdminSetting::getBool(self::SETTING_ENABLED, true),
            'channel_mode' => $channelMode,
            'first_reminder_hours' => max(1, min(168, (int) AdminSetting::getValue(self::SETTING_FIRST_REMINDER_HOURS, (string) self::FIRST_REMINDER_AFTER_HOURS))),
            'expire_hours' => max(1, min(720, (int) AdminSetting::getValue(self::SETTING_EXPIRE_HOURS, (string) self::EXPIRE_AFTER_HOURS))),
            'allow_manual_send' => AdminSetting::getBool(self::SETTING_ALLOW_MANUAL_SEND, true),
            'allow_manual_reminder' => AdminSetting::getBool(self::SETTING_ALLOW_MANUAL_REMINDER, true),
            'photo_in_summary' => AdminSetting::getBool(self::SETTING_PHOTO_IN_SUMMARY, true),
            'profile_link_enabled' => AdminSetting::getBool(self::SETTING_PROFILE_LINK_ENABLED, true),
            'request_cooldown_days' => max(0, min(365, (int) AdminSetting::getValue(self::SETTING_REQUEST_COOLDOWN_DAYS, '30'))),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'];
    }

    private function cleanDetail(?string $value, int $limit): ?string
    {
        $value = $value !== null ? trim($value) : null;
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit($value, $limit, '');
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedDetail(?string $value, array $allowed): ?string
    {
        $value = $this->cleanDetail($value, 64);

        return $value !== null && in_array($value, $allowed, true) ? $value : null;
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
