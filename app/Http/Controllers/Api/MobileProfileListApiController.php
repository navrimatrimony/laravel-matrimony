<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\HiddenProfile;
use App\Models\MatrimonyProfile;
use App\Models\Shortlist;
use App\Models\User;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileProfileDisplayPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class MobileProfileListApiController extends Controller
{
    public function __construct(
        protected MobileProfileDisplayPresenter $displayPresenter,
        protected MobileDiscoveryFilterService $discovery,
    ) {}

    public function shortlisted(Request $request): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$user, $owner] = $context;

        $entries = Shortlist::query()
            ->with('shortlistedProfile')
            ->where('owner_profile_id', $owner->id)
            ->latest()
            ->get();

        return $this->listResponse('shortlisted', 'Shortlisted profiles loaded.', $entries, 'shortlistedProfile', 'listed_at', $user, [
            'can_remove' => true,
        ]);
    }

    public function blocked(Request $request): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$user, $owner] = $context;

        $entries = Block::query()
            ->with('blockedProfile')
            ->where('blocker_profile_id', $owner->id)
            ->latest()
            ->get();

        return $this->listResponse('blocked', 'Blocked profiles loaded.', $entries, 'blockedProfile', 'blocked_at', $user, [
            'can_unblock' => true,
        ]);
    }

    public function hidden(Request $request): JsonResponse
    {
        if (! Schema::hasTable('hidden_profiles')) {
            return response()->json([
                'success' => true,
                'message' => 'Hidden profiles are not available.',
                'available' => false,
                'list' => 'hidden',
                'profiles' => [],
                'meta' => [
                    'count' => 0,
                ],
            ]);
        }

        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$user, $owner] = $context;

        $entries = HiddenProfile::query()
            ->with('hiddenProfile')
            ->where('owner_profile_id', $owner->id)
            ->latest()
            ->get();

        return $this->listResponse('hidden', 'Hidden profiles loaded.', $entries, 'hiddenProfile', 'hidden_at', $user, [
            'can_unhide' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: MatrimonyProfile}|JsonResponse
     */
    private function viewerContext(Request $request): array|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $owner = $user->matrimonyProfile;
        if (! $owner instanceof MatrimonyProfile) {
            return $this->error('Please create your profile first.', 422);
        }

        return [$user, $owner];
    }

    /**
     * @param  iterable<object>  $entries
     * @param  array<string, bool>  $actionState
     */
    private function listResponse(
        string $list,
        string $message,
        iterable $entries,
        string $relation,
        string $dateKey,
        User $user,
        array $actionState
    ): JsonResponse {
        $viewerProfile = $user->matrimonyProfile;
        $profiles = [];

        foreach ($entries as $entry) {
            $profile = $entry->{$relation} ?? null;
            if (! $profile instanceof MatrimonyProfile) {
                continue;
            }

            if ((int) $profile->id === (int) $viewerProfile->id) {
                continue;
            }

            $profiles[] = $this->profilePayload(
                $profile,
                $user,
                $entry->created_at,
                $dateKey,
                $actionState
            );
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'available' => true,
            'list' => $list,
            'profiles' => $profiles,
            'meta' => [
                'count' => count($profiles),
            ],
        ]);
    }

    /**
     * @param  array<string, bool>  $actionState
     * @return array<string, mixed>
     */
    private function profilePayload(
        MatrimonyProfile $profile,
        User $user,
        mixed $createdAt,
        string $dateKey,
        array $actionState
    ): array {
        $display = $this->displayPresenter->forListCard($profile, $user);
        $card = is_array($display['card'] ?? null) ? $display['card'] : [];
        $canOpen = $this->discovery->isAllowedTarget($user, $profile);

        return array_merge([
            'id' => (int) $profile->id,
            'profile_id' => (int) $profile->id,
            'name' => $card['name'] ?? null,
            'age_label' => $card['age_label'] ?? null,
            'height_label' => $card['height_label'] ?? null,
            'community_label' => $card['community_label'] ?? null,
            'education_label' => $card['education_label'] ?? null,
            'occupation_label' => $card['occupation_label'] ?? null,
            'location_label' => $card['location_label'] ?? null,
            'primary_photo_url' => $card['primary_photo_url'] ?? null,
            'verified' => (bool) ($card['verified'] ?? false),
            'premium' => (bool) ($card['premium'] ?? false),
            $dateKey => $this->dateValue($createdAt),
            'can_open_profile' => $canOpen,
            'display' => $display,
            'action_state' => array_merge([
                'can_open_profile' => $canOpen,
                'can_remove' => false,
                'can_unblock' => false,
                'can_unhide' => false,
            ], $actionState),
        ], $actionState);
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
