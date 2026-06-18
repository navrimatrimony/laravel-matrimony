<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\HiddenProfile;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Shortlist;
use App\Models\User;
use App\Services\ProfileLifecycleService;
use App\Services\ProfileVisibilityPolicyService;
use App\Services\ViewTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileActionApiController extends Controller
{
    public function shortlist(Request $request, int $id): JsonResponse
    {
        $context = $this->actionContext($request, $id);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$owner, $target] = $context;

        if (! ProfileLifecycleService::canInitiateInteraction($owner)) {
            return $this->error('Your profile cannot initiate interactions in its current state.', 403);
        }

        if (! ProfileLifecycleService::canReceiveInterest($target)) {
            return $this->error('This profile cannot receive interactions in its current state.', 403);
        }

        $exists = Shortlist::query()
            ->where('owner_profile_id', $owner->id)
            ->where('shortlisted_profile_id', $target->id)
            ->exists();

        if ($exists) {
            return $this->success('Already shortlisted.', $owner, $target);
        }

        Shortlist::query()->firstOrCreate([
            'owner_profile_id' => $owner->id,
            'shortlisted_profile_id' => $target->id,
        ]);

        return $this->success('Added to shortlist.', $owner, $target);
    }

    public function unshortlist(Request $request, int $id): JsonResponse
    {
        $context = $this->actionContext($request, $id, requireVisible: false);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$owner, $target] = $context;

        Shortlist::query()
            ->where('owner_profile_id', $owner->id)
            ->where('shortlisted_profile_id', $target->id)
            ->delete();

        return $this->success('Removed from shortlist.', $owner, $target);
    }

    public function hide(Request $request, int $id): JsonResponse
    {
        $context = $this->actionContext($request, $id);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$owner, $target] = $context;

        $exists = HiddenProfile::query()
            ->where('owner_profile_id', $owner->id)
            ->where('hidden_profile_id', $target->id)
            ->exists();

        if ($exists) {
            return $this->success('Profile already hidden.', $owner, $target);
        }

        HiddenProfile::query()->firstOrCreate([
            'owner_profile_id' => $owner->id,
            'hidden_profile_id' => $target->id,
        ]);

        return $this->success('Profile hidden.', $owner, $target);
    }

    public function block(Request $request, int $id): JsonResponse
    {
        $context = $this->actionContext($request, $id, requireVisible: false);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$blocker, $blocked] = $context;

        $exists = Block::query()
            ->where('blocker_profile_id', $blocker->id)
            ->where('blocked_profile_id', $blocked->id)
            ->exists();

        if (! $exists && ! $this->canViewTarget($blocked, $request->user(), $blocker)) {
            return $this->error('Profile not found.', 404);
        }

        DB::transaction(function () use ($blocker, $blocked): void {
            $this->deleteInterestsBetween($blocker, $blocked);
            $this->deleteShortlistsBetween($blocker, $blocked);

            Block::query()->firstOrCreate([
                'blocker_profile_id' => $blocker->id,
                'blocked_profile_id' => $blocked->id,
            ]);
        });

        return $this->success($exists ? 'Profile already blocked.' : 'Profile blocked.', $blocker, $blocked);
    }

    public function unblock(Request $request, int $id): JsonResponse
    {
        $context = $this->actionContext($request, $id, requireVisible: false);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$blocker, $blocked] = $context;

        Block::query()
            ->where('blocker_profile_id', $blocker->id)
            ->where('blocked_profile_id', $blocked->id)
            ->delete();

        return $this->success('Profile unblocked.', $blocker, $blocked);
    }

    /**
     * @return array{0: MatrimonyProfile, 1: MatrimonyProfile}|JsonResponse
     */
    private function actionContext(Request $request, int $targetId, bool $requireVisible = true): array|JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', 401);
        }

        $owner = $user->matrimonyProfile;
        if (! $owner) {
            return $this->error('Please create your profile first.', 422);
        }

        $target = MatrimonyProfile::query()->find($targetId);
        if (! $target) {
            return $this->error('Profile not found.', 404);
        }

        if ((int) $owner->id === (int) $target->id) {
            return $this->error('You cannot perform this action on your own profile.', 422);
        }

        if ($requireVisible && ! $this->canViewTarget($target, $user, $owner)) {
            return $this->error('Profile not found.', 404);
        }

        return [$owner, $target];
    }

    private function canViewTarget(MatrimonyProfile $target, User $viewer, MatrimonyProfile $viewerProfile): bool
    {
        if (! ProfileLifecycleService::isVisibleToOthers($target)) {
            return false;
        }

        if (ViewTrackingService::isBlocked($viewerProfile->id, $target->id)) {
            return false;
        }

        return ProfileVisibilityPolicyService::canViewProfile($target, $viewer);
    }

    private function deleteInterestsBetween(MatrimonyProfile $first, MatrimonyProfile $second): void
    {
        Interest::query()
            ->where(function ($query) use ($first, $second): void {
                $query->where('sender_profile_id', $first->id)
                    ->where('receiver_profile_id', $second->id);
            })
            ->orWhere(function ($query) use ($first, $second): void {
                $query->where('sender_profile_id', $second->id)
                    ->where('receiver_profile_id', $first->id);
            })
            ->delete();
    }

    private function deleteShortlistsBetween(MatrimonyProfile $first, MatrimonyProfile $second): void
    {
        Shortlist::query()
            ->where(function ($query) use ($first, $second): void {
                $query->where('owner_profile_id', $first->id)
                    ->where('shortlisted_profile_id', $second->id);
            })
            ->orWhere(function ($query) use ($first, $second): void {
                $query->where('owner_profile_id', $second->id)
                    ->where('shortlisted_profile_id', $first->id);
            })
            ->delete();
    }

    private function success(string $message, MatrimonyProfile $owner, MatrimonyProfile $target): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'state' => $this->state($owner, $target),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * @return array{shortlisted: bool, hidden: bool, blocked: bool}
     */
    private function state(MatrimonyProfile $owner, MatrimonyProfile $target): array
    {
        return [
            'shortlisted' => Shortlist::query()
                ->where('owner_profile_id', $owner->id)
                ->where('shortlisted_profile_id', $target->id)
                ->exists(),
            'hidden' => HiddenProfile::query()
                ->where('owner_profile_id', $owner->id)
                ->where('hidden_profile_id', $target->id)
                ->exists(),
            'blocked' => Block::query()
                ->where('blocker_profile_id', $owner->id)
                ->where('blocked_profile_id', $target->id)
                ->exists(),
        ];
    }
}
