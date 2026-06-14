<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\ContactAccessService;
use App\Services\FeatureUsageService;
use App\Services\MediationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileContactActionController extends Controller
{
    public function __construct(
        protected ContactAccessService $contactAccess,
        protected MediationRequestService $mediationRequestService,
    ) {}

    public function revealContact(Request $request, MatrimonyProfile $matrimony_profile): RedirectResponse|JsonResponse
    {
        $user = auth()->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $matrimony_profile->id) {
            abort(403);
        }
        /** @var int Same authenticated user id as {@see MatrimonyProfileController::show} (GET). */
        $userId = (int) $user->id;

        $wantsJson = $request->wantsJson()
            || $request->ajax()
            || $request->header('X-Contact-Reveal') === '1';

        if ($this->isSuchakOnlyProfile($matrimony_profile)) {
            return $this->revealSuchakContact($request, $user, $matrimony_profile, $wantsJson);
        }

        $visibilitySettings = DB::table('profile_visibility_settings')
            ->where('profile_id', $matrimony_profile->id)
            ->first();

        $featureUsage = app(FeatureUsageService::class);

        if (! $featureUsage->shouldBypassUsageLimits($user)) {
            if (! $featureUsage->canUse($userId, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT)) {
                if ($wantsJson) {
                    return response()->json(['message' => __('contact_access.upgrade_required')], 422);
                }

                return redirect()->route('plans.index');
            }
        }

        try {
            $result = $this->contactAccess->consumePaidContactReveal($user, $matrimony_profile, $visibilitySettings);
        } catch (\InvalidArgumentException $e) {
            if ($wantsJson) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('matrimony.profile.show', $matrimony_profile)
                ->with('error', $e->getMessage());
        }

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'message' => __('contact_access.reveal_success'),
                'phone' => $result['phone'] ?? '',
                'email' => $result['email'] ?? null,
                'contact_usage' => $this->contactUsagePayloadForJson($user, $featureUsage),
            ]);
        }

        // No global success flash: unlocked state is visible on full page reload; avoids header jump + top banner.
        return redirect()->route('matrimony.profile.show', $matrimony_profile);
    }

    /**
     * @return array{line1: string, line2: string, low_warning: bool, low_warning_text: ?string}
     */
    private function contactUsagePayloadForJson(User $user, FeatureUsageService $featureUsage): array
    {
        $snap = $featureUsage->getContactViewUsageSnapshot($user);

        if (! empty($snap['is_unlimited'])) {
            return [
                'line1' => __('profile.usage_contacts_used_line', [
                    'used' => $snap['used'],
                    'limit' => '∞',
                ]),
                'line2' => __('profile.usage_contacts_remaining_unlimited'),
                'low_warning' => false,
                'low_warning_text' => null,
            ];
        }

        $low = is_numeric($snap['limit'])
            && (int) $snap['limit'] > 0
            && is_numeric($snap['remaining'])
            && (int) $snap['remaining'] <= 2;

        return [
            'line1' => __('profile.usage_contacts_used_line', [
                'used' => $snap['used'],
                'limit' => $snap['limit'],
            ]),
            'line2' => __('profile.usage_contacts_remaining_line', [
                'remaining' => $snap['remaining'],
            ]),
            'low_warning' => $low,
            'low_warning_text' => $low ? __('profile.usage_contacts_low_warning') : null,
        ];
    }

    public function mediatorRequest(Request $request, MatrimonyProfile $matrimony_profile): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $matrimony_profile->id) {
            abort(403);
        }
        if ($this->isSuchakOnlyProfile($matrimony_profile)) {
            abort(403, 'Suchak-managed profiles must use the Suchak request pipeline.');
        }

        try {
            $this->mediationRequestService->createFromProfile($user, $matrimony_profile);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('matrimony.profile.show', $matrimony_profile)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('matrimony.profile.show', $matrimony_profile)
            ->with('success', __('contact_access.mediator_success'));
    }

    private function isSuchakOnlyProfile(MatrimonyProfile $profile): bool
    {
        $publiclyRoutableSuchakQuery = SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->where('matrimony_profile_id', $profile->id);

        if ((clone $publiclyRoutableSuchakQuery)
            ->whereIn('representation_mode', SuchakProfileRepresentation::SUCHAK_CREATED_MODES)
            ->exists()) {
            return true;
        }

        if (! (clone $publiclyRoutableSuchakQuery)->exists() || ! Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode')) {
            return false;
        }

        $mode = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->value('contact_routing_mode');

        return ProfileVisibilitySetting::normalizeContactRoutingMode(is_string($mode) ? $mode : null)
            === ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY;
    }

    private function revealSuchakContact(
        Request $request,
        User $user,
        MatrimonyProfile $profile,
        bool $wantsJson,
    ): RedirectResponse|JsonResponse {
        $representation = $this->routableSuchakRepresentationFor(
            $profile,
            (int) $request->input('representation_id', 0) ?: null,
        );

        if (! $representation) {
            abort(404);
        }

        try {
            $result = $this->contactAccess->consumeRoutedContactReveal(
                $user,
                $profile,
                $this->suchakPhoneFor($representation),
            );
        } catch (\InvalidArgumentException $e) {
            if ($wantsJson) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('matrimony.profile.show', $profile)
                ->with('error', $e->getMessage());
        }

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'message' => __('contact_access.reveal_success'),
                'phone' => $result['phone'] ?? '',
                'email' => null,
                'suchak_name' => $representation->suchakAccount?->suchak_name,
                'contact_usage' => $this->contactUsagePayloadForJson($user, app(FeatureUsageService::class)),
            ]);
        }

        return redirect()->route('matrimony.profile.show', $profile);
    }

    private function routableSuchakRepresentationFor(MatrimonyProfile $profile, ?int $representationId): ?SuchakProfileRepresentation
    {
        $query = SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount.contactNumbers' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderByDesc('is_whatsapp')
                    ->orderBy('id'),
            ])
            ->publiclyRoutable()
            ->where('matrimony_profile_id', $profile->id)
            ->orderBy('id');

        if ($representationId !== null) {
            $query->whereKey($representationId);
        }

        return $query->first();
    }

    private function suchakPhoneFor(SuchakProfileRepresentation $representation): string
    {
        $account = $representation->suchakAccount;
        $contactNumber = $account?->contactNumbers
            ?->first(fn ($number): bool => (bool) ($number->is_active ?? false) && trim((string) ($number->phone_number ?? '')) !== '');

        if ($contactNumber) {
            return trim((string) $contactNumber->phone_number);
        }

        $whatsapp = trim((string) ($account?->whatsapp_number ?? ''));
        if ($whatsapp !== '') {
            return $whatsapp;
        }

        return trim((string) ($account?->mobile_number ?? ''));
    }
}
