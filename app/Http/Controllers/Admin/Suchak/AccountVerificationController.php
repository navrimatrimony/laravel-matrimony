<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Modules\Suchak\Services\SuchakAccountLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

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

    public function show(SuchakAccount $suchakAccount): View
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

        return view('admin.suchak.accounts.show', [
            'suchakAccount' => $suchakAccount,
            'activityLogs' => $activityLogs,
        ]);
    }

    public function approve(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runLifecycleAction(
            fn () => $lifecycleService->approve(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, '')
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

        return redirect()
            ->route('admin.suchak.accounts.show', $account)
            ->with('success', $successMessage);
    }
}
