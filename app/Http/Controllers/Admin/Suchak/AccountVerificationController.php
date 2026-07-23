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
    /** Review queue is driven by these, not by raw created_at order. */
    public const SORT_SMART = 'smart';

    public const SORT_WAITING = 'waiting';

    public const SORT_NEWEST = 'newest';

    public const SORT_NAME = 'name';

    public const READINESS_READY = 'ready';

    public const READINESS_INCOMPLETE = 'incomplete';

    /** @return array<int, string> */
    public static function sortOptions(): array
    {
        return [self::SORT_SMART, self::SORT_WAITING, self::SORT_NEWEST, self::SORT_NAME];
    }

    /** @return array<int, string> */
    public static function verificationStatuses(): array
    {
        return [
            SuchakAccount::VERIFICATION_PENDING,
            SuchakAccount::VERIFICATION_VERIFIED,
            SuchakAccount::VERIFICATION_REJECTED,
            SuchakAccount::VERIFICATION_SUSPENDED,
            SuchakAccount::VERIFICATION_ARCHIVED,
        ];
    }

    /** @return array<int, string> */
    public static function businessTypes(): array
    {
        return [
            SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            SuchakAccount::BUSINESS_TYPE_BUREAU,
            SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
        ];
    }

    public function index(Request $request): View
    {
        $allowedStatuses = self::verificationStatuses();

        $status = $request->query('verification_status');
        $status = in_array($status, $allowedStatuses, true) ? $status : null;

        $businessType = $request->query('business_type');
        $businessType = in_array($businessType, self::businessTypes(), true) ? $businessType : null;

        $readiness = $request->query('readiness');
        $readiness = in_array($readiness, [self::READINESS_READY, self::READINESS_INCOMPLETE], true)
            ? $readiness
            : null;

        $sort = $request->query('sort');
        $sort = in_array($sort, self::sortOptions(), true) ? $sort : self::SORT_SMART;

        $search = trim((string) $request->query('q', ''));
        $search = $search === '' ? null : Str::limit($search, 100, '');

        $accounts = SuchakAccount::query()
            ->with(['user', 'cityLocation', 'districtLocation'])
            ->when($status, fn ($query) => $query->where('verification_status', $status))
            ->when($businessType, fn ($query) => $query->where('business_type', $businessType))
            ->when(
                $readiness === self::READINESS_READY,
                fn ($query) => $query->whereNotNull('registration_completed_at')
            )
            ->when(
                $readiness === self::READINESS_INCOMPLETE,
                fn ($query) => $query->whereNull('registration_completed_at')
            )
            ->when($search, function ($query) use ($search): void {
                // Mobile is the real identity here (most Suchaks register by OTP
                // and never add an email), so it is searched alongside names.
                $digits = preg_replace('/\D+/', '', $search) ?? '';
                $query->where(function ($inner) use ($search, $digits): void {
                    $inner->where('suchak_name', 'like', '%'.$search.'%')
                        ->orWhere('suchak_name_mr', 'like', '%'.$search.'%')
                        ->orWhere('office_name', 'like', '%'.$search.'%')
                        ->orWhere('office_name_mr', 'like', '%'.$search.'%');
                    if ($digits !== '') {
                        $inner->orWhere('mobile_number', 'like', '%'.$digits.'%')
                            ->orWhere('whatsapp_number', 'like', '%'.$digits.'%');
                    }
                });
            });

        $this->applySort($accounts, $sort);

        $accounts = $accounts->paginate(20)->withQueryString();

        return view('admin.suchak.accounts.index', [
            'accounts' => $accounts,
            'allowedStatuses' => $allowedStatuses,
            'businessTypes' => self::businessTypes(),
            'status' => $status,
            'businessType' => $businessType,
            'readiness' => $readiness,
            'sort' => $sort,
            'search' => $search,
            'queueCounts' => $this->queueCounts(),
            'duplicateKeys' => $this->duplicateKeys($accounts->getCollection()),
        ]);
    }

    /**
     * Default order is the review queue, not the audit log: accounts that are
     * actually reviewable (registration finished) come first, oldest wait at the
     * top, so nothing quietly ages out of sight.
     */
    private function applySort(\Illuminate\Database\Eloquent\Builder $query, string $sort): void
    {
        match ($sort) {
            self::SORT_WAITING => $query->orderBy('created_at'),
            self::SORT_NEWEST => $query->orderByDesc('created_at'),
            self::SORT_NAME => $query->orderBy('suchak_name'),
            default => $query
                ->orderByRaw("CASE WHEN verification_status = ? THEN 0 ELSE 1 END", [SuchakAccount::VERIFICATION_PENDING])
                ->orderByRaw('CASE WHEN registration_completed_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('created_at'),
        };
    }

    /**
     * Workload at a glance. "Ready" and "Incomplete" split the pending bucket —
     * an abandoned half-finished registration is not review work.
     *
     * @return array<string, int>
     */
    private function queueCounts(): array
    {
        $pending = SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_PENDING);

        return [
            'ready' => (clone $pending)->whereNotNull('registration_completed_at')->count(),
            'incomplete' => (clone $pending)->whereNull('registration_completed_at')->count(),
            'verified' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)->count(),
            'rejected' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_REJECTED)->count(),
            'suspended' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_SUSPENDED)->count(),
        ];
    }

    /**
     * Flags rows on this page that share a mobile number or a normalised name,
     * so two near-identical "राज" rows are visibly related instead of looking
     * like an admin mistake. Advisory only — it never blocks an action.
     *
     * @param  \Illuminate\Support\Collection<int, SuchakAccount>  $accounts
     * @return array<int, string>  account id => duplicate reason
     */
    private function duplicateKeys($accounts): array
    {
        $byMobile = [];
        $byName = [];
        foreach ($accounts as $account) {
            $mobile = preg_replace('/\D+/', '', (string) $account->mobile_number) ?? '';
            if ($mobile !== '') {
                $byMobile[$mobile][] = $account->id;
            }
            $name = Str::lower(trim((string) $account->suchak_name));
            if ($name !== '') {
                $byName[$name][] = $account->id;
            }
        }

        $flags = [];
        foreach ($byMobile as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $flags[$id] = 'Same mobile as another row';
            }
        }
        foreach ($byName as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $flags[$id] ??= 'Same name as another row';
            }
        }

        return $flags;
    }

    /**
     * Bulk approve/reject straight from the queue. Deliberately routed through
     * the same SuchakAccountLifecycleService the single-account actions use, so
     * activity logging, public-status handling and guard rails are identical —
     * there is no second approval path.
     */
    public function bulkAction(
        Request $request,
        SuchakAccountLifecycleService $lifecycleService,
        SuchakPolicyService $policyService
    ): RedirectResponse {
        $validated = $request->validate([
            'bulk_action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'account_ids' => ['required', 'array', 'min:1', 'max:50'],
            'account_ids.*' => ['integer'],
        ]);

        $accounts = SuchakAccount::query()
            ->whereIn('id', $validated['account_ids'])
            ->get();

        if ($accounts->isEmpty()) {
            return back()->with('error', 'No matching Suchak accounts were selected.');
        }

        $admin = $request->user();
        $ip = $request->ip();
        $agent = Str::limit((string) $request->userAgent(), 512, '');
        $approve = $validated['bulk_action'] === 'approve';

        $done = 0;
        $failures = [];

        foreach ($accounts as $account) {
            try {
                if ($approve) {
                    $lifecycleService->approve(
                        $account,
                        $admin,
                        $validated['reason'],
                        $ip,
                        $agent,
                        $policyService->autoPublishesOnApproval()
                    );
                } else {
                    $lifecycleService->reject($account, $admin, $validated['reason'], $ip, $agent);
                }
                $done++;
            } catch (InvalidArgumentException $exception) {
                // One bad row must not silently swallow the rest, and must not
                // be reported as success.
                $failures[] = ($account->suchak_name ?: 'Account #'.$account->id).': '.$exception->getMessage();
            }
        }

        $verb = $approve ? 'approved' : 'rejected';
        $redirect = redirect()->route('admin.suchak.accounts.index', $request->only([
            'verification_status', 'business_type', 'readiness', 'sort', 'q', 'page',
        ]));

        if ($failures !== []) {
            return $redirect->with('error', sprintf(
                '%d %s, %d skipped — %s',
                $done,
                $verb,
                count($failures),
                implode(' | ', array_slice($failures, 0, 3))
            ));
        }

        return $redirect->with('success', sprintf('%d Suchak account(s) %s.', $done, $verb));
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
            $params = [];
            $status = (string) request()->input('return_status', '');
            $queue = (string) request()->input('return_queue', '');
            if (in_array($status, PhotoReviewController::statuses(), true)) {
                $params['status'] = $status;
            }
            if (in_array($queue, PhotoReviewController::queues(), true)) {
                $params['queue'] = $queue;
            }

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
