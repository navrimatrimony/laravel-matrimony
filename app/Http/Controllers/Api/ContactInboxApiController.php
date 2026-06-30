<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactGrant;
use App\Models\ContactRequest;
use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\CommunicationPolicyService;
use App\Services\ContactRequestService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactInboxApiController extends Controller
{
    public function __construct(
        protected ContactRequestService $contactRequestService,
        protected MobileProfileDisplayPresenter $displayPresenter,
    ) {}

    public function store(Request $request, int $id, MobileDiscoveryFilterService $discovery): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $viewerProfile = $user->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return $this->error('Please create your profile first.', 422);
        }

        $profile = MatrimonyProfile::query()->with('user')->find($id);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error('Profile not found.', 404);
        }

        if ((int) $viewerProfile->id === (int) $profile->id || (int) $profile->user_id === (int) $user->id) {
            return $this->error('Cannot request your own contact.', 403);
        }

        if (! $discovery->isAllowedTarget($user, $profile)) {
            return $this->error('Profile not found.', 404);
        }

        if ($this->isSuchakRoutedProfile($profile)) {
            return $this->error('Contact request is not available in mobile for this profile yet.', 422);
        }

        $receiver = $profile->user;
        if (! $receiver instanceof User) {
            return $this->error('Contact request is not available for this profile.', 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|in:talk_to_family,meet,need_more_details,discuss_marriage_timeline,other',
            'other_reason_text' => 'required_if:reason,other|nullable|string|max:500',
            'requested_scopes' => 'required|array',
            'requested_scopes.*' => 'string|in:email,phone,whatsapp',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $requestedScopes = array_values(array_unique($data['requested_scopes'] ?? []));

        try {
            $contactRequest = $this->contactRequestService->createRequest(
                $user,
                $receiver,
                $data['reason'],
                $requestedScopes,
                $data['other_reason_text'] ?? null,
            );
        } catch (ValidationException $exception) {
            return $this->validationException($exception);
        }

        $contactRequest->load($this->requestRelations());
        $profile->refresh()->loadMissing('user');
        $display = $this->displayPresenter->forProfile($profile, $user);

        return response()->json([
            'success' => true,
            'message' => 'Contact request sent. You will be notified when they respond.',
            'request' => $this->requestPayload($contactRequest, 'sent'),
            'display' => [
                'contact' => $display['contact'] ?? null,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $received = ContactRequest::query()
            ->with($this->requestRelations())
            ->where('receiver_id', $user->id)
            ->where('type', ContactRequest::TYPE_CONTACT)
            ->where('status', ContactRequest::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ContactRequest $contactRequest): array => $this->requestPayload($contactRequest, 'received'))
            ->values();

        $sent = ContactRequest::query()
            ->with($this->requestRelations())
            ->where('sender_id', $user->id)
            ->where('type', ContactRequest::TYPE_CONTACT)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn (ContactRequest $contactRequest): array => $this->requestPayload($contactRequest, 'sent'))
            ->values();

        return response()->json([
            'success' => true,
            'received' => $received,
            'sent' => $sent,
            'meta' => [
                'reason_options' => $this->reasonOptions(),
                'scope_options' => $this->scopeOptions(),
                'duration_options' => $this->durationOptions(),
            ],
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $contactRequest = ContactRequest::query()->with($this->requestRelations())->find($id);
        if (! $contactRequest instanceof ContactRequest) {
            return $this->error('Contact request not found.', 404);
        }
        if ((int) $contactRequest->receiver_id !== (int) $user->id) {
            return $this->error('Only the receiver can approve this request.', 403);
        }

        $validator = Validator::make($request->all(), [
            'granted_scopes' => 'required|array',
            'granted_scopes.*' => 'string|in:email,phone,whatsapp',
            'duration_key' => 'required|string|in:approve_once,approve_7_days,approve_30_days',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $grantedScopes = array_values(array_unique($data['granted_scopes'] ?? []));

        try {
            $grant = $this->contactRequestService->approve(
                $contactRequest,
                $user,
                $grantedScopes,
                $data['duration_key'],
            );
        } catch (ValidationException $exception) {
            return $this->validationException($exception);
        }

        $contactRequest->refresh()->load($this->requestRelations());

        return response()->json([
            'success' => true,
            'message' => 'Contact access granted.',
            'request' => $this->requestPayload($contactRequest, 'received'),
            'grant' => $this->grantPayload($grant),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $contactRequest = ContactRequest::query()->with($this->requestRelations())->find($id);
        if (! $contactRequest instanceof ContactRequest) {
            return $this->error('Contact request not found.', 404);
        }
        if ((int) $contactRequest->receiver_id !== (int) $user->id) {
            return $this->error('Only the receiver can reject this request.', 403);
        }

        try {
            $this->contactRequestService->reject($contactRequest, $user);
        } catch (ValidationException $exception) {
            return $this->validationException($exception);
        }

        $contactRequest->refresh()->load($this->requestRelations());

        return response()->json([
            'success' => true,
            'message' => 'Request rejected. Sender cannot request again until the cooling period ends.',
            'request' => $this->requestPayload($contactRequest, 'received'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function requestRelations(): array
    {
        return [
            'grant',
            'sender.matrimonyProfile.gender',
            'sender.matrimonyProfile.religion',
            'sender.matrimonyProfile.caste',
            'sender.matrimonyProfile.location',
            'receiver.matrimonyProfile.gender',
            'receiver.matrimonyProfile.religion',
            'receiver.matrimonyProfile.caste',
            'receiver.matrimonyProfile.location',
        ];
    }

    private function requestPayload(ContactRequest $contactRequest, string $direction): array
    {
        return [
            'id' => $contactRequest->id,
            'direction' => $direction,
            'status' => $contactRequest->status,
            'reason' => $contactRequest->reason,
            'reason_label' => $this->reasonLabel($contactRequest->reason),
            'other_reason_text' => $contactRequest->other_reason_text,
            'requested_scopes' => array_values($contactRequest->requested_scopes ?? []),
            'expires_at' => $this->dateString($contactRequest->expires_at),
            'cooldown_ends_at' => $this->dateString($contactRequest->cooldown_ends_at),
            'created_at' => $this->dateString($contactRequest->created_at),
            'sender_profile' => $this->profileSummary($contactRequest->sender?->matrimonyProfile),
            'receiver_profile' => $this->profileSummary($contactRequest->receiver?->matrimonyProfile),
            'grant' => $contactRequest->grant instanceof ContactGrant ? $this->grantPayload($contactRequest->grant) : null,
        ];
    }

    private function profileSummary(?MatrimonyProfile $profile): ?array
    {
        if (! $profile instanceof MatrimonyProfile) {
            return null;
        }

        $profile->loadMissing(['gender', 'religion', 'caste', 'location']);

        return [
            'id' => $profile->id,
            'name' => $this->cleanString($profile->full_name) ?? 'Profile',
            'age' => $this->age($profile),
            'profile_photo' => $profile->photo_approved !== false ? $this->cleanString($profile->profile_photo) : null,
            'profile_photo_url' => $profile->photo_approved !== false ? $profile->profile_photo_url : null,
            'community' => $this->communityLabel($profile),
            'location' => $this->locationLabel($profile),
        ];
    }

    private function grantPayload(ContactGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'granted_scopes' => array_values($grant->granted_scopes ?? []),
            'valid_until' => $this->dateString($grant->valid_until),
            'revoked_at' => $this->dateString($grant->revoked_at),
        ];
    }

    private function communityLabel(MatrimonyProfile $profile): ?string
    {
        return $this->joinClean([
            $this->cleanString($profile->religion?->name ?? $profile->religion?->label ?? null),
            $this->cleanString($profile->caste?->name ?? $profile->caste?->label ?? null),
        ]);
    }

    private function locationLabel(MatrimonyProfile $profile): ?string
    {
        if (method_exists($profile, 'residenceLocationDisplayLine')) {
            $line = $this->cleanString($profile->residenceLocationDisplayLine());
            if ($line !== null) {
                return $line;
            }
        }

        return $this->cleanString($profile->location?->name ?? $profile->location?->label ?? null);
    }

    private function age(MatrimonyProfile $profile): ?int
    {
        $date = $this->cleanString($profile->date_of_birth);
        if ($date === null) {
            return null;
        }

        try {
            return Carbon::parse($date)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function reasonLabel(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $config = CommunicationPolicyService::getConfig();

        return $config['request_reasons'][$reason] ?? Str::headline($reason);
    }

    private function reasonOptions(): array
    {
        $config = CommunicationPolicyService::getConfig();
        $reasons = $config['request_reasons'] ?? [];

        return collect($reasons)
            ->map(fn ($label, $key): array => [
                'key' => (string) $key,
                'label' => (string) $label,
            ])
            ->values()
            ->all();
    }

    private function scopeOptions(): array
    {
        $config = CommunicationPolicyService::getConfig();
        $scopes = array_keys(array_filter($config['allowed_contact_scopes'] ?? []));

        return collect($scopes)
            ->map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->scopeLabel($key),
            ])
            ->values()
            ->all();
    }

    private function durationOptions(): array
    {
        $config = CommunicationPolicyService::getConfig();
        $durations = array_keys(array_filter($config['grant_duration_options'] ?? []));

        return collect($durations)
            ->map(fn (string $key): array => [
                'key' => $key,
                'label' => match ($key) {
                    'approve_7_days' => '7 days',
                    'approve_30_days' => '30 days',
                    default => 'Approve once (24 hours)',
                },
            ])
            ->values()
            ->all();
    }

    private function scopeLabel(string $key): string
    {
        return match ($key) {
            'email' => 'Email',
            'phone' => 'Phone',
            'whatsapp' => 'WhatsApp',
            default => Str::headline($key),
        };
    }

    private function isSuchakRoutedProfile(MatrimonyProfile $profile): bool
    {
        if (! Schema::hasTable('suchak_profile_representations')) {
            return false;
        }

        $publiclyRoutableSuchakQuery = SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->where('matrimony_profile_id', $profile->id);

        if ((clone $publiclyRoutableSuchakQuery)
            ->whereIn('representation_mode', SuchakProfileRepresentation::SUCHAK_CREATED_MODES)
            ->exists()) {
            return true;
        }

        if (! (clone $publiclyRoutableSuchakQuery)->exists()
            || ! Schema::hasTable('profile_visibility_settings')
            || ! Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode')) {
            return false;
        }

        $mode = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->value('contact_routing_mode');

        return ProfileVisibilitySetting::normalizeContactRoutingMode(is_string($mode) ? $mode : null)
            === ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $this->cleanString($value);
    }

    private function joinClean(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn ($value): bool => $this->cleanString($value) !== null));

        return $parts === [] ? null : implode(' • ', $parts);
    }

    private function cleanString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function validationException(ValidationException $exception): JsonResponse
    {
        return $this->validationError($exception->errors(), $exception->getMessage());
    }

    private function validationError(array $errors, ?string $fallbackMessage = null): JsonResponse
    {
        $message = collect($errors)->flatten()->first() ?: $fallbackMessage ?: 'The given data was invalid.';

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
