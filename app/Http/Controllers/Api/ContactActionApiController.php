<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRequestPresenter;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\ContactAccessService;
use App\Support\Suchak\SuchakContactRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Suchak-routed: the number that unlocks is the SUCHAK's own business
        // number, never the candidate's. Same service call the website makes
        // (ProfileContactActionController::revealSuchakContact), so the same
        // quota/plan gate applies and there is no second reveal path.
        if (SuchakContactRouting::isRouted($profile)) {
            return $this->revealSuchakContact($request, $user, $profile);
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

    /**
     * Mobile twin of the website's Suchak reveal. Reveals the Suchak's own
     * number through the existing ContactAccessService::consumeRoutedContactReveal
     * so plan/quota accounting is identical on both surfaces. The candidate's
     * number is not read, not masked, not returned.
     */
    private function revealSuchakContact(Request $request, User $user, MatrimonyProfile $profile): JsonResponse
    {
        $representationId = (int) $request->input('representation_id', 0) ?: null;
        $representation = SuchakContactRouting::routableRepresentationFor($profile, $representationId);

        if (! $representation instanceof SuchakProfileRepresentation) {
            return $this->error(__('profile.suchak_request_no_suchak'), 404);
        }

        try {
            $result = $this->contactAccess->consumeRoutedContactReveal(
                $user,
                $profile,
                SuchakContactRouting::accountPhone($representation->suchakAccount),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $profile->refresh()->loadMissing('user');
        $display = $this->displayPresenter->forProfile($profile, $user);

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_contact_number_revealed'),
            'contact' => [
                'phone' => $result['phone'] ?? null,
                'email' => null,
            ],
            'suchak' => app(SuchakRequestPresenter::class)->suchakBlock($representation),
            'display' => [
                'contact' => $display['contact'] ?? null,
            ],
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
