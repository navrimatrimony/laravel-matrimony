<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakVisitConfirmation;
use App\Modules\Suchak\Services\SuchakGrowthRewardService;
use App\Modules\Suchak\Services\SuchakPolicyService;
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
    public function index(Request $request, SuchakPolicyService $policyService): View
    {
        $status = (string) $request->query('visit_status', '');
        $status = in_array($status, SuchakVisitConfirmation::STATUSES, true) ? $status : null;

        return view('admin.suchak.visits.index', [
            'status' => $status,
            'statuses' => SuchakVisitConfirmation::STATUSES,
            // The platform's own price for a meeting reward, and the ceiling that
            // binds a typed figure while no price is published. Both are read
            // here so the screen states the rule it is about to apply instead of
            // presenting an open box — see SuchakVisitConfirmationService::boundPayoutAmount().
            'visitRewardRule' => SuchakGrowthRewardRule::visitRewardInForce(),
            'visitPayoutCeiling' => $policyService->visitPayoutMaxAmount(),
            'visitPayoutRequiresSecondAdmin' => $policyService->visitPayoutRequiresSecondAdmin(),
            'visits' => SuchakVisitConfirmation::query()
                // `platformPayout` is eager-loaded for the single-actor flag: the
                // screen compares who admin-confirmed the meeting against who
                // qualified its payout, so that fact is visible on the row rather
                // than only in the audit trail.
                ->with(['suchakAccount', 'helperSuchakAccount', 'paymentContext', 'platformPayout'])
                ->when($status, fn ($query) => $query->where('visit_status', $status))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    /**
     * THE DOOR FOR THE PRICE ITSELF.
     *
     * `SuchakGrowthRewardService::createRewardRule()` shipped with no caller
     * anywhere outside tests — no route, no controller, no seeder. Binding the
     * meeting payout to a rule nobody could create would have replaced an
     * unbounded amount with an unreachable one, so the price gets a door on the
     * same screen where it is spent.
     *
     * Trigger and type are fixed here rather than accepted from the form: this
     * action publishes a platform VISIT reward, and letting a request name
     * `platform_payment_confirmed` would let the meetings screen mint referral
     * rules. Rules are immutable and undeletable by design, so changing the
     * price means publishing a later rule; the newest in force wins.
     */
    public function storeRewardRule(
        Request $request,
        SuchakGrowthRewardService $growthRewardService,
    ): RedirectResponse {
        $validated = $request->validate([
            'rule_key' => ['required', 'string', 'min:3', 'max:96'],
            'reward_amount' => ['required', 'numeric', 'min:0.01'],
            'reward_currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        return $this->runVisitAction(
            fn () => $growthRewardService->createRewardRule(
                $request->user(),
                [
                    'rule_key' => $validated['rule_key'],
                    'reward_trigger' => SuchakGrowthRewardRule::TRIGGER_PLATFORM_VISIT_CONFIRMED,
                    'reward_type' => SuchakGrowthRewardRule::TYPE_CASH,
                    'reward_amount' => $validated['reward_amount'],
                    'reward_currency' => $validated['reward_currency'] ?? 'INR',
                    'starts_at' => $validated['starts_at'] ?? null,
                    'ends_at' => $validated['ends_at'] ?? null,
                ],
                $request->ip(),
                (string) $request->userAgent(),
            ),
            'Platform visit reward rule published.',
        );
    }

    /**
     * THE DOOR FOR STOPPING THE PRICE.
     *
     * Publishing a later rule can only ever say "the price is now different". This is the only way
     * to say "the platform no longer pays for meetings at all" — before it, `is_active` had two
     * readers and no writer that could make either of them false, so a published visit reward
     * stood forever. Withdrawal is one-way and reprices nothing; every payout already qualified
     * under the rule reads back exactly as it did.
     */
    public function withdrawRewardRule(
        Request $request,
        SuchakGrowthRewardRule $rewardRule,
        SuchakGrowthRewardService $growthRewardService,
    ): RedirectResponse {
        $validated = $request->validate([
            'withdraw_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runVisitAction(
            fn () => $growthRewardService->withdrawRewardRule(
                $request->user(),
                $rewardRule,
                $validated['withdraw_reason'],
                $request->ip(),
                (string) $request->userAgent(),
            ),
            'Platform visit reward rule withdrawn.',
        );
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
        // `amount` is NULLABLE now, and that is the change. It used to be
        // [required, numeric, min:0.01] — no ceiling, no reference to any
        // platform-owned figure — so whatever an admin typed became platform
        // money. The bound lives in the service, where it can see whether a
        // platform visit reward rule is in force; when one is, the form sends no
        // amount at all and the rule supplies it. Repeating the ceiling here as
        // a `max:` would put the same number in two places and let them drift.
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
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
