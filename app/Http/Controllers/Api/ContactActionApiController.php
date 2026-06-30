<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\ContactAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ContactActionApiController extends Controller
{
    public function __construct(
        protected ContactAccessService $contactAccess,
        protected MobileProfileDisplayPresenter $displayPresenter,
    ) {}

    public function reveal(Request $request, int $id, MobileDiscoveryFilterService $discovery): JsonResponse
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
            return $this->error('Contact unlock is not available on your own profile.', 403);
        }

        if (! $discovery->isAllowedTarget($user, $profile)) {
            return $this->error('Profile not found.', 404);
        }

        if ($this->isSuchakRoutedProfile($profile)) {
            return $this->error('Contact reveal is not available in mobile for this profile yet.', 422);
        }

        $visibilitySettings = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->first();

        try {
            $result = $this->contactAccess->consumePaidContactReveal($user, $profile, $visibilitySettings);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $profile->refresh()->loadMissing('user');
        $display = $this->displayPresenter->forProfile($profile, $user);

        return response()->json([
            'success' => true,
            'message' => __('contact_access.reveal_success'),
            'contact' => [
                'phone' => $result['phone'] ?? null,
                'email' => $result['email'] ?? null,
            ],
            'display' => [
                'contact' => $display['contact'] ?? null,
            ],
        ]);
    }

    private function isSuchakRoutedProfile(MatrimonyProfile $profile): bool
    {
        $publiclyRoutableSuchakQuery = SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->where('matrimony_profile_id', $profile->id);

        if ((clone $publiclyRoutableSuchakQuery)
            ->whereIn('representation_mode', SuchakProfileRepresentation::SUCHAK_CREATED_MODES)
            ->exists()) {
            return true;
        }

        if (! (clone $publiclyRoutableSuchakQuery)->exists()
            || ! Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode')) {
            return false;
        }

        $mode = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->value('contact_routing_mode');

        return ProfileVisibilitySetting::normalizeContactRoutingMode(is_string($mode) ? $mode : null)
            === ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
