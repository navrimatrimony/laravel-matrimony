<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakEntitlementService;
use App\Modules\Suchak\Services\SuchakPaymentStatusService;
use App\Support\Suchak\SuchakMvpFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        SuchakPaymentStatusService $paymentStatusService,
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
        $hasPaidPlan = $paymentStatusService->activeSubscriptionFor($account) !== null;

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
                    // Thin APK adapters — approved path, else pending verification preview.
                    'profile_photo_url' => $this->profilePhotoUrl($account),
                    'organization_logo_url' => $this->organizationLogoUrl($account),
                ],
                // Track A only — never PayU / platform billing fields.
                'payment_identity' => $account->trackAPaymentIdentity(),
                'access' => [
                    'can_operate' => $accessService->canOperate($account),
                    'can_prepare_customers' => $accessService->canPrepareCustomers($account),
                    'can_publicly_route' => $accessService->canPubliclyRoute($account),
                    // Biodata text/file intake is a paid-plan capability.
                    'can_use_biodata_intake' => $hasPaidPlan,
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

    private function profilePhotoUrl(SuchakAccount $account): ?string
    {
        $approved = $this->publicStorageUrl($account->profile_photo_path);
        if ($approved !== null) {
            return $approved;
        }

        $record = $account->verificationRecords()
            ->where('verification_type', SuchakVerificationRecord::TYPE_PROFILE_PHOTO)
            ->latest('id')
            ->first();

        return $this->verificationPreviewUrl($account, $record);
    }

    private function organizationLogoUrl(SuchakAccount $account): ?string
    {
        $logo = $account->verificationRecords()
            ->where('verification_type', SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO)
            ->latest('id')
            ->first();

        return $this->verificationPreviewUrl($account, $logo);
    }

    private function verificationPreviewUrl(
        SuchakAccount $account,
        ?SuchakVerificationRecord $record,
    ): ?string {
        if ($record === null) {
            return null;
        }

        $sourcePath = trim((string) ($record->document_path ?? ''));
        if ($sourcePath === '') {
            return null;
        }

        if (str_starts_with($sourcePath, 'http://') || str_starts_with($sourcePath, 'https://')) {
            return $sourcePath;
        }

        if (Storage::disk('public')->exists($sourcePath)) {
            return asset('storage/'.$sourcePath);
        }

        if (! Storage::disk('local')->exists($sourcePath)) {
            return null;
        }

        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $publicPath = 'suchak/profile-photos/'.$account->id.'/apk-preview-'.$record->id.'.'.$extension;
        if (! Storage::disk('public')->exists($publicPath)) {
            Storage::disk('public')->put(
                $publicPath,
                Storage::disk('local')->get($sourcePath),
            );
        }

        return asset('storage/'.$publicPath);
    }

    private function publicStorageUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return asset('storage/'.$path);
    }
}
