<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use App\Models\User;
use App\Services\CommunicationPolicyService;
use App\Services\NotificationPlatformSettingsService;
use App\Services\ProfileVisibilitySettingsDefaultsService;
use App\Services\UserNotificationPreferencesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MobileSettingsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        return response()->json($this->settingsPayload($user));
    }

    public function updatePrivacy(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $profile = $this->profileFor($user);
        if (! $profile instanceof MatrimonyProfile || ! $this->privacyStorageAvailable()) {
            return $this->error('Privacy settings are not available for this account.', 422);
        }

        $rules = $this->privacyValidationRules();
        $provided = array_intersect(array_keys($request->all()), array_keys($rules));
        if ($provided === []) {
            return $this->error('No supported privacy setting was provided.', 422);
        }

        $validated = $request->validate(array_intersect_key($rules, array_flip($provided)));
        $visibility = $this->visibilitySettingFor($profile);
        $resolved = $visibility?->resolvedContactVisibility()
            ?? ProfileVisibilitySetting::defaultResolvedContactVisibility();
        $defaults = $this->visibilityDefaults($profile);

        $values = [
            'visibility_scope' => (string) ($visibility?->visibility_scope ?? $defaults['visibility_scope'] ?? 'public'),
            'show_photo_to' => (string) ($visibility?->show_photo_to ?? $defaults['show_photo_to'] ?? 'all'),
            'contact_visibility_rule' => (string) ($resolved['rule'] ?? 'anyone'),
            'contact_visibility_strictness' => (string) ($resolved['strictness'] ?? 'balanced'),
            'contact_visibility_id_verified_only' => (bool) ($resolved['filters']['id_verified_only'] ?? false),
            'contact_visibility_photo_only' => (bool) ($resolved['filters']['photo_only'] ?? false),
            'contact_visibility_require_contact_request' => (bool) ($resolved['require_contact_request'] ?? false),
            'contact_visibility_approval_required' => (bool) ($resolved['approval_required'] ?? false),
            'contact_routing_mode' => $visibility?->resolvedContactRoutingMode()
                ?? ProfileVisibilitySetting::CONTACT_ROUTING_DIRECT_AND_SUCHAK,
            'hide_from_blocked_users' => (bool) ($visibility?->hide_from_blocked_users
                ?? $defaults['hide_from_blocked_users']
                ?? true),
        ];

        foreach ($validated as $key => $value) {
            $values[$key] = $value;
        }

        $payload = [
            'visibility_scope' => $values['visibility_scope'],
            'show_photo_to' => $values['show_photo_to'],
            'show_contact_to' => $this->deriveLegacyShowContactTo(
                (string) $values['contact_visibility_rule'],
                (bool) $values['contact_visibility_require_contact_request'],
            ),
            'hide_from_blocked_users' => (bool) $values['hide_from_blocked_users'],
            'contact_visibility_json' => [
                'rule' => $values['contact_visibility_rule'],
                'strictness' => $values['contact_visibility_strictness'],
                'filters' => [
                    'id_verified_only' => (bool) $values['contact_visibility_id_verified_only'],
                    'photo_only' => (bool) $values['contact_visibility_photo_only'],
                ],
                'approval_required' => (bool) $values['contact_visibility_approval_required'],
                'require_contact_request' => (bool) $values['contact_visibility_require_contact_request'],
            ],
            'contact_routing_mode' => $values['contact_routing_mode'],
        ];

        ProfileVisibilitySetting::query()->updateOrCreate(
            ['profile_id' => $profile->id],
            $payload,
        );

        return response()->json($this->settingsPayload($user, 'Privacy settings saved.'));
    }

    public function updateCommunication(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $profile = $this->profileFor($user);
        if (! $profile instanceof MatrimonyProfile || ! $this->profileColumnExists('contact_unlock_mode')) {
            return $this->error('Communication settings are not available for this account.', 422);
        }

        $validated = $request->validate([
            'contact_unlock_mode' => ['required', Rule::in(['after_interest_accepted', 'never', 'admin_only'])],
        ]);

        $profile->update([
            'contact_unlock_mode' => $validated['contact_unlock_mode'],
        ]);

        return response()->json($this->settingsPayload($user, 'Communication settings saved.'));
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'notification_preferences')) {
            return $this->error('Notification preferences are not available for this account.', 422);
        }

        $validated = $request->validate([
            UserNotificationPreferencesService::KEY_EMAIL_ALERTS => ['sometimes', 'boolean'],
            UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE => ['sometimes', 'boolean'],
            UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST => ['sometimes', 'boolean'],
        ]);

        $platform = app(NotificationPlatformSettingsService::class);
        $updates = [];

        if (array_key_exists(UserNotificationPreferencesService::KEY_EMAIL_ALERTS, $validated)
            && $platform->mailEnabled()
            && trim((string) ($user->email ?? '')) !== '') {
            $updates[UserNotificationPreferencesService::KEY_EMAIL_ALERTS] =
                (bool) $validated[UserNotificationPreferencesService::KEY_EMAIL_ALERTS];
        }

        if (array_key_exists(UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE, $validated)
            && $platform->inactiveReminderEnabled()) {
            $updates[UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE] =
                (bool) $validated[UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE];
        }

        if (array_key_exists(UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST, $validated)
            && $platform->newMatchesDigestEnabled()) {
            $updates[UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST] =
                (bool) $validated[UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST];
        }

        if ($updates !== []) {
            app(UserNotificationPreferencesService::class)->saveForUser($user, $updates);
        }

        return response()->json($this->settingsPayload($user->fresh() ?? $user, 'Notification preferences saved.'));
    }

    private function settingsPayload(User $user, string $message = 'Settings loaded.'): array
    {
        $profile = $this->profileFor($user);

        return [
            'success' => true,
            'message' => $message,
            'settings' => [
                'account' => $this->accountPayload($user, $profile),
                'privacy' => $this->privacyPayload($profile),
                'communication' => $this->communicationPayload($profile),
                'notifications' => $this->notificationsPayload($user),
                'security' => $this->securityPayload($user),
            ],
        ];
    }

    private function accountPayload(User $user, ?MatrimonyProfile $profile): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) ($user->name ?? ''),
            'email' => $user->email,
            'mobile' => $user->mobile,
            'profile_id' => $profile?->id !== null ? (int) $profile->id : null,
            'has_profile' => $profile instanceof MatrimonyProfile,
        ];
    }

    private function privacyPayload(?MatrimonyProfile $profile): array
    {
        if (! $profile instanceof MatrimonyProfile) {
            return $this->unavailableSection('Complete your matrimony profile to manage privacy settings.');
        }

        if (! $this->privacyStorageAvailable()) {
            return $this->unavailableSection('Privacy settings storage is not available.');
        }

        $visibility = $this->visibilitySettingFor($profile);
        $resolved = $visibility?->resolvedContactVisibility()
            ?? ProfileVisibilitySetting::defaultResolvedContactVisibility();
        $defaults = $this->visibilityDefaults($profile);

        return [
            'available' => true,
            'editable' => true,
            'fields' => [
                'visibility_scope' => $this->selectField(
                    (string) ($visibility?->visibility_scope ?? $defaults['visibility_scope'] ?? 'public'),
                    true,
                    [
                        ['value' => 'public', 'label' => 'Public'],
                        ['value' => 'premium_only', 'label' => 'Premium only'],
                        ['value' => 'hidden', 'label' => 'Hidden'],
                    ],
                ),
                'show_photo_to' => $this->selectField(
                    (string) ($visibility?->show_photo_to ?? $defaults['show_photo_to'] ?? 'all'),
                    true,
                    [
                        ['value' => 'all', 'label' => 'All viewers'],
                        ['value' => 'premium', 'label' => 'Premium viewers'],
                        ['value' => 'accepted_interest', 'label' => 'After interest accepted'],
                    ],
                ),
                'contact_visibility_rule' => $this->selectField(
                    (string) ($resolved['rule'] ?? 'anyone'),
                    true,
                    [
                        ['value' => 'anyone', 'label' => 'Anyone with eligible access'],
                        ['value' => 'interest', 'label' => 'After accepted interest'],
                        ['value' => 'matching', 'label' => 'Matching profiles only'],
                        ['value' => 'none', 'label' => 'No one'],
                    ],
                ),
                'contact_visibility_strictness' => $this->selectField(
                    (string) ($resolved['strictness'] ?? 'balanced'),
                    true,
                    [
                        ['value' => 'relaxed', 'label' => 'Relaxed'],
                        ['value' => 'balanced', 'label' => 'Balanced'],
                        ['value' => 'strict', 'label' => 'Strict'],
                    ],
                ),
                'contact_visibility_id_verified_only' => $this->boolField((bool) ($resolved['filters']['id_verified_only'] ?? false), true),
                'contact_visibility_photo_only' => $this->boolField((bool) ($resolved['filters']['photo_only'] ?? false), true),
                'contact_visibility_require_contact_request' => $this->boolField((bool) ($resolved['require_contact_request'] ?? false), true),
                'contact_visibility_approval_required' => $this->boolField((bool) ($resolved['approval_required'] ?? false), true),
                'contact_routing_mode' => $this->selectField(
                    $visibility?->resolvedContactRoutingMode()
                        ?? ProfileVisibilitySetting::CONTACT_ROUTING_DIRECT_AND_SUCHAK,
                    true,
                    [
                        ['value' => ProfileVisibilitySetting::CONTACT_ROUTING_DIRECT_AND_SUCHAK, 'label' => 'Direct and Suchak'],
                        ['value' => ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY, 'label' => 'Suchak only'],
                    ],
                ),
                'hide_from_blocked_users' => $this->boolField(
                    (bool) ($visibility?->hide_from_blocked_users
                        ?? $defaults['hide_from_blocked_users']
                        ?? true),
                    false,
                ),
            ],
            'read_only' => [
                'legacy_profile_visibility_mode' => $profile->profile_visibility_mode,
            ],
        ];
    }

    private function communicationPayload(?MatrimonyProfile $profile): array
    {
        $policy = CommunicationPolicyService::getConfig();

        if (! $profile instanceof MatrimonyProfile) {
            return array_merge(
                $this->unavailableSection('Complete your matrimony profile to manage communication settings.'),
                ['admin_policy' => $this->communicationPolicyPayload($policy)],
            );
        }

        $editable = $this->profileColumnExists('contact_unlock_mode');

        return [
            'available' => $editable,
            'editable' => $editable,
            'fields' => [
                'contact_unlock_mode' => $this->selectField(
                    (string) ($profile->contact_unlock_mode ?? 'after_interest_accepted'),
                    $editable,
                    [
                        ['value' => 'after_interest_accepted', 'label' => 'After interest accepted'],
                        ['value' => 'never', 'label' => 'Never show contact'],
                        ['value' => 'admin_only', 'label' => 'Admin only'],
                    ],
                ),
            ],
            'admin_policy' => $this->communicationPolicyPayload($policy),
        ];
    }

    private function notificationsPayload(User $user): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'notification_preferences')) {
            return $this->unavailableSection('Notification preferences storage is not available.');
        }

        $prefs = app(UserNotificationPreferencesService::class)->forUser($user);
        $platform = app(NotificationPlatformSettingsService::class);

        return [
            'available' => true,
            'editable' => true,
            'fields' => [
                UserNotificationPreferencesService::KEY_EMAIL_ALERTS => $this->boolField(
                    (bool) $prefs[UserNotificationPreferencesService::KEY_EMAIL_ALERTS],
                    $platform->mailEnabled() && trim((string) ($user->email ?? '')) !== '',
                ),
                UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE => $this->boolField(
                    (bool) $prefs[UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE],
                    $platform->inactiveReminderEnabled(),
                ),
                UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST => $this->boolField(
                    (bool) $prefs[UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST],
                    $platform->newMatchesDigestEnabled(),
                ),
            ],
        ];
    }

    private function securityPayload(User $user): array
    {
        return [
            'available' => true,
            'editable' => false,
            'fields' => [
                'email_verified' => $this->boolField($user->email_verified_at !== null, false),
                'mobile_verified' => $this->boolField($user->mobile_verified_at !== null, false),
            ],
        ];
    }

    private function communicationPolicyPayload(array $policy): array
    {
        return [
            'editable' => false,
            'contact_request_mode' => $policy['contact_request_mode'] ?? null,
            'allowed_contact_scopes' => $policy['allowed_contact_scopes'] ?? [],
            'messaging_mode' => $policy['messaging_mode'] ?? null,
            'allow_messaging' => (bool) ($policy['allow_messaging'] ?? true),
        ];
    }

    private function visibilitySettingFor(MatrimonyProfile $profile): ?ProfileVisibilitySetting
    {
        return ProfileVisibilitySetting::query()
            ->where('profile_id', $profile->id)
            ->first();
    }

    private function visibilityDefaults(MatrimonyProfile $profile): array
    {
        return $profile->isShowcaseProfile()
            ? ProfileVisibilitySettingsDefaultsService::showcaseDefaults()
            : ProfileVisibilitySettingsDefaultsService::registrationDefaults();
    }

    private function privacyValidationRules(): array
    {
        return [
            'visibility_scope' => ['sometimes', Rule::in(['public', 'premium_only', 'hidden'])],
            'show_photo_to' => ['sometimes', Rule::in(['all', 'premium', 'accepted_interest'])],
            'contact_visibility_rule' => ['sometimes', Rule::in(['anyone', 'interest', 'matching', 'none'])],
            'contact_visibility_strictness' => ['sometimes', Rule::in(['relaxed', 'balanced', 'strict'])],
            'contact_visibility_id_verified_only' => ['sometimes', 'boolean'],
            'contact_visibility_photo_only' => ['sometimes', 'boolean'],
            'contact_visibility_require_contact_request' => ['sometimes', 'boolean'],
            'contact_visibility_approval_required' => ['sometimes', 'boolean'],
            'contact_routing_mode' => ['sometimes', Rule::in(ProfileVisibilitySetting::CONTACT_ROUTING_MODES)],
        ];
    }

    private function deriveLegacyShowContactTo(string $rule, bool $requireContactRequest): string
    {
        if ($rule === 'none') {
            return 'no_one';
        }
        if ($requireContactRequest) {
            return 'unlock_only';
        }
        if ($rule === 'interest') {
            return 'accepted_interest';
        }

        return 'everyone';
    }

    private function privacyStorageAvailable(): bool
    {
        if (! Schema::hasTable('profile_visibility_settings')) {
            return false;
        }

        foreach ([
            'profile_id',
            'visibility_scope',
            'show_photo_to',
            'show_contact_to',
            'hide_from_blocked_users',
            'contact_visibility_json',
            'contact_routing_mode',
        ] as $column) {
            if (! Schema::hasColumn('profile_visibility_settings', $column)) {
                return false;
            }
        }

        return true;
    }

    private function profileColumnExists(string $column): bool
    {
        if (! Schema::hasTable('matrimony_profiles')) {
            return false;
        }

        return Schema::hasColumn('matrimony_profiles', $column);
    }

    private function profileFor(User $user): ?MatrimonyProfile
    {
        return MatrimonyProfile::query()
            ->where('user_id', $user->id)
            ->first();
    }

    private function boolField(bool $value, bool $editable): array
    {
        return [
            'type' => 'boolean',
            'value' => $value,
            'editable' => $editable,
        ];
    }

    private function selectField(string $value, bool $editable, array $options): array
    {
        return [
            'type' => 'select',
            'value' => $value,
            'editable' => $editable,
            'options' => $options,
        ];
    }

    private function unavailableSection(string $message): array
    {
        return [
            'available' => false,
            'editable' => false,
            'message' => $message,
            'fields' => [],
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
