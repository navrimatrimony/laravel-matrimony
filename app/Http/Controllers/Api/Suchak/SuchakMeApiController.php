<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakEntitlementService;
use App\Support\Suchak\SuchakMvpFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter over existing Suchak account/access services.
 * No business-rule changes.
 */
class SuchakMeApiController extends Controller
{
    public function __invoke(
        Request $request,
        SuchakAccessService $accessService,
        SuchakEntitlementService $entitlementService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        $limits = $entitlementService->currentFeatureLimits($account);

        return response()->json([
            'success' => true,
            'message' => 'Suchak account loaded.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'mobile' => $user->mobile,
                ],
                'account' => [
                    'id' => $account->id,
                    'suchak_name' => $account->suchak_name,
                    'office_name' => $account->office_name,
                    'business_type' => $account->business_type,
                    'employee_count' => $account->employee_count,
                    'verification_status' => $account->verification_status,
                    'public_status' => $account->public_status,
                    'verified_at' => $account->verified_at?->toIso8601String(),
                    'registration_completed' => $account->isRegistrationComplete(),
                    'registration_completed_at' => $account->registration_completed_at?->toIso8601String(),
                    'onboarding_step' => $account->onboarding_step,
                ],
                // Track A only — never PayU / platform billing fields.
                'payment_identity' => $account->trackAPaymentIdentity(),
                'access' => [
                    'can_operate' => $accessService->canOperate($account),
                    'can_prepare_customers' => $accessService->canPrepareCustomers($account),
                    'can_publicly_route' => $accessService->canPubliclyRoute($account),
                ],
                'entitlements' => $limits,
                'mvp_surface' => [
                    'nav' => config('suchak_mvp.nav'),
                    'nav_subitems' => config('suchak_mvp.nav_subitems'),
                    'dashboard_tabs' => config('suchak_mvp.dashboard_tabs'),
                    'visible_dashboard_tabs' => SuchakMvpFeatures::visibleDashboardTabs(),
                ],
            ],
        ]);
    }
}
