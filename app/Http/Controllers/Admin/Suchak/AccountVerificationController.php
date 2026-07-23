<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakVerificationRecord;
use App\Modules\Suchak\Services\SuchakAccountLifecycleService;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use App\Modules\Suchak\Services\SuchakPaymentStatusService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountVerificationController extends Controller
{
    public function index(Request $request): View
    {
        $allowedStatuses = [
            SuchakAccount::VERIFICATION_PENDING,
            SuchakAccount::VERIFICATION_VERIFIED,
            SuchakAccount::VERIFICATION_REJECTED,
            SuchakAccount::VERIFICATION_SUSPENDED,
            SuchakAccount::VERIFICATION_ARCHIVED,
        ];

        $status = $request->query('verification_status');
        $status = in_array($status, $allowedStatuses, true) ? $status : null;

        $accounts = SuchakAccount::query()
            ->with('user')
            ->when($status, fn ($query) => $query->where('verification_status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.suchak.accounts.index', [
            'accounts' => $accounts,
            'allowedStatuses' => $allowedStatuses,
            'status' => $status,
        ]);
    }

    public function show(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakBillingCatalogService $billingCatalogService,
        SuchakPaymentStatusService $paymentStatusService
    ): View
    {
        $suchakAccount->load([
            'user',
            'verificationRecords' => fn ($query) => $query->with('adminUser')->latest(),
        ]);

        $activityLogs = SuchakActivityLog::query()
            ->with(['actorUser', 'adminAuditLog'])
            ->where('suchak_account_id', $suchakAccount->id)
            ->latest('occurred_at')
            ->limit(20)
            ->get();

        $consentEvidence = SuchakConsent::query()
            ->with(['events.actorUser', 'representation'])
            ->where('suchak_account_id', $suchakAccount->id)
            ->latest()
            ->limit(20)
            ->get();

        return view('admin.suchak.accounts.show', [
            'suchakAccount' => $suchakAccount,
            'activityLogs' => $activityLogs,
            'consentEvidence' => $consentEvidence,
            'assignablePlans' => $billingCatalogService
                ->catalogForAdmin($request->user())
                ->where('is_active', true)
                ->values(),
            'activeSubscription' => $paymentStatusService->activeSubscriptionFor($suchakAccount),
        ]);
    }

    public function approve(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService,
        SuchakPolicyService $policyService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->approve(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, ''),
                $policyService->autoPublishesOnApproval()
            ),
            $suchakAccount,
            'Suchak account approved.'
        );
    }

    public function reject(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->reject(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, '')
            ),
            $suchakAccount,
            'Suchak account rejected.'
        );
    }

    public function suspend(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->suspend(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, '')
            ),
            $suchakAccount,
            'Suchak account suspended.'
        );
    }

    public function archive(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->archive(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, '')
            ),
            $suchakAccount,
            'Suchak account archived.'
        );
    }

    public function reactivate(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->reactivate(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, '')
            ),
            $suchakAccount,
            'Suchak account reactivated.'
        );
    }

    public function updatePublicStatus(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $request->validate([
            'public_status' => ['required', 'string', Rule::in([
                SuchakAccount::PUBLIC_HIDDEN,
                SuchakAccount::PUBLIC_ACTIVE,
                SuchakAccount::PUBLIC_INACTIVE,
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->updatePublicStatus(
                $suchakAccount,
                $request->user(),
                $validated['public_status'],
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, '')
            ),
            $suchakAccount,
            'Suchak public status updated.'
        );
    }

    public function approveVerificationRecord(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakVerificationRecord $verificationRecord,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        return $this->reviewVerificationRecord(
            $request,
            $suchakAccount,
            $verificationRecord,
            $lifecycleService,
            SuchakVerificationRecord::STATUS_APPROVED,
            'Suchak verification record approved.'
        );
    }

    public function rejectVerificationRecord(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakVerificationRecord $verificationRecord,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        return $this->reviewVerificationRecord(
            $request,
            $suchakAccount,
            $verificationRecord,
            $lifecycleService,
            SuchakVerificationRecord::STATUS_REJECTED,
            'Suchak verification record rejected.'
        );
    }

    public function viewVerificationDocument(
        SuchakAccount $suchakAccount,
        SuchakVerificationRecord $verificationRecord
    ): BinaryFileResponse {
        abort_unless($verificationRecord->suchak_account_id === $suchakAccount->id, 404);
        abort_if(blank($verificationRecord->document_path), 404);
        abort_unless(Storage::disk('local')->exists($verificationRecord->document_path), 404);

        return response()->file(Storage::disk('local')->path($verificationRecord->document_path));
    }

    /**
     * @return array{reason: string}
     */
    private function validateReason(Request $request): array
    {
        return $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
    }

    private function runLifecycleAction(callable $callback, SuchakAccount $account, string $successMessage): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if (request()->input('return_to') === 'photo_reviews') {
            $queue = (string) request()->input('return_queue', '');
            $params = in_array($queue, PhotoReviewController::queues(), true)
                ? ['queue' => $queue]
                : [];

            return redirect()
                ->route('admin.suchak.photo-reviews.index', $params)
                ->with('success', $successMessage);
        }

        return redirect()
            ->route('admin.suchak.accounts.show', $account)
            ->with('success', $successMessage);
    }

    private function reviewVerificationRecord(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakVerificationRecord $verificationRecord,
        SuchakAccountLifecycleService $lifecycleService,
        string $adminStatus,
        string $successMessage
    ): RedirectResponse {
        abort_unless($verificationRecord->suchak_account_id === $suchakAccount->id, 404);

        $validated = $this->validateReason($request);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->updateVerificationRecordStatus(
                $verificationRecord,
                $request->user(),
                $adminStatus,
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, '')
            ),
            $suchakAccount,
            $successMessage
        );
    }
}
