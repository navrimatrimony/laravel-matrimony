<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakVisitConfirmation;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The ADMIN half of the meeting engine (blueprint 5.1 blocker B2).
 *
 * `confirmByAdmin()` and `qualifyPayoutForVisit()` are admin-only inside
 * SuchakVisitConfirmationService — both open with
 * `accessService->assertAdmin()` — and had no route, so under the default
 * `user_and_admin` policy no meeting in production could ever reach `confirmed`
 * and no visit payout could ever be qualified.
 *
 * The admin dispute action is here too rather than on SafetyController: a
 * visit dispute is raised THROUGH the visit (it writes `dispute_id`,
 * `payout_hold_id` and `refund_review_status` back onto the meeting row), while
 * SafetyController owns disputes that already exist. Reviewing, closing and
 * freezing against that dispute stays where it is.
 *
 * Authorisation is not re-implemented here. The route group already carries
 * auth + admin + admin.section, and the service performs its own admin
 * assertion, which is what also protects it when called from anywhere else.
 */
class VisitConfirmationController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('visit_status', '');
        $status = in_array($status, SuchakVisitConfirmation::STATUSES, true) ? $status : null;

        return view('admin.suchak.visits.index', [
            'status' => $status,
            'statuses' => SuchakVisitConfirmation::STATUSES,
            'visits' => SuchakVisitConfirmation::query()
                ->with(['suchakAccount', 'helperSuchakAccount', 'paymentContext'])
                ->when($status, fn ($query) => $query->where('visit_status', $status))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function confirm(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'confirmation_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runVisitAction(
            fn () => $visitConfirmationService->confirmByAdmin(
                $visit,
                $request->user(),
                $validated,
                $request->ip(),
                (string) $request->userAgent(),
            ),
            'Suchak visit confirmed by admin.',
        );
    }

    public function dispute(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'dispute_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runVisitAction(
            fn () => $visitConfirmationService->disputeVisit(
                $visit,
                $request->user(),
                $validated,
                $request->ip(),
                (string) $request->userAgent(),
            ),
            'Suchak visit dispute opened and platform payout held.',
        );
    }

    public function qualifyPayout(
        Request $request,
        SuchakVisitConfirmation $visit,
        SuchakVisitConfirmationService $visitConfirmationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'qualification_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runVisitAction(
            fn () => $visitConfirmationService->qualifyPayoutForVisit(
                $visit,
                $request->user(),
                $validated,
                $request->ip(),
                (string) $request->userAgent(),
            ),
            'Suchak visit payout qualified.',
        );
    }

    private function runVisitAction(callable $callback, string $successMessage): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.suchak.visits.index')
            ->with('success', $successMessage);
    }
}
