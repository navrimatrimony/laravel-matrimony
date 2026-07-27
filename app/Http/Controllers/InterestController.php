<?php

namespace App\Http\Controllers;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Services\Interest\InterestActionOutcome;
use App\Services\Interest\InterestActionService;
use App\Services\Interest\ReceivedInterestTeaserBuilder;
use App\Services\Interest\ReceivedInterestTeaserPolicy;
use App\Services\Interest\SuchakRoutedInterestService;
use App\Services\InterestSendLimitService;
use App\Support\ErrorFactory;
use App\Support\RuleResultResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/*
|--------------------------------------------------------------------------
| InterestController (SSOT v3.1 FINAL)
|--------------------------------------------------------------------------
|
| GOLDEN RULE:
| Interest = MatrimonyProfile → MatrimonyProfile
| User = authentication only
|
*/

class InterestController extends Controller
{
    /** @var list<string> */
    private const INTEREST_STATUS_FILTERS = ['all', 'pending', 'accepted', 'rejected'];

    /**
     * Refusals that are an authorization failure rather than a rule denial: the web surface has
     * always answered those with a bare 403 and keeps doing so.
     *
     * @var list<string>
     */
    private const WEB_FORBIDDEN_CODES = ['INTEREST_API_NOT_RECEIVER', 'INTEREST_API_NOT_SENDER'];

    public function __construct(
        private readonly InterestActionService $interestActions,
        private readonly InterestSendLimitService $interestSendLimit,
        private readonly ReceivedInterestTeaserBuilder $receivedTeasers,
        private readonly SuchakRoutedInterestService $routedInterests,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Send Interest
    |--------------------------------------------------------------------------
    |
    | Route:
    | POST /interests/send/{matrimony_profile}
    |
    | Meaning:
    | - Logged-in user च्या MatrimonyProfile कडून
    | - समोरच्या user च्या MatrimonyProfile ला
    |
    */

    // 🔒 SSOT-COMPLIANT ROUTE MODEL BINDING
    // Route param: {matrimony_profile_id}

    public function store(MatrimonyProfile $matrimony_profile_id)
    {
        // 🔒 AUTH USER (authentication only)
        $authUser = auth()->user();

        if (! $authUser) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        // 🔒 All interest rules live in InterestActionService — shared with the mobile API.
        $outcome = $this->interestActions->send($authUser, $matrimony_profile_id);

        if (! $outcome->ok) {
            return $this->renderRefusal($outcome);
        }

        return back()->with('success', $outcome->message());
    }

    /**
     * Renders a refused interest action on the web surface. {@see RuleResultResponder::toResponse()}
     * keeps the historical dual behaviour (flash on a page post, JSON when the caller asked for it).
     */
    private function renderRefusal(InterestActionOutcome $outcome): JsonResponse|RedirectResponse
    {
        $error = $outcome->error ?? ErrorFactory::generic();

        if (in_array($error->code, self::WEB_FORBIDDEN_CODES, true)) {
            abort(403);
        }

        if ($error->code === 'INTEREST_API_NEED_PROFILE' && ! request()->expectsJson()) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', $error->message);
        }

        return RuleResultResponder::toResponse($error, $outcome->status);
    }

    /**
     * Interests hub: default tab is received; use ?tab=sent for sent.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $tab = strtolower((string) $request->query('tab', 'received')) === 'sent' ? 'sent' : 'received';
        $statusFilter = $this->normalizeInterestStatusFilter($request->query('status'));

        return $this->renderInterestsPage($request, $tab, $statusFilter);
    }

    /*
    |--------------------------------------------------------------------------
    | Sent / Received (legacy URLs; same hub as {@see index})
    |--------------------------------------------------------------------------
    */
    public function sent(Request $request): View|RedirectResponse
    {
        return $this->renderInterestsPage(
            $request,
            'sent',
            $this->normalizeInterestStatusFilter($request->query('status'))
        );
    }

    public function received(Request $request): View|RedirectResponse
    {
        return $this->renderInterestsPage(
            $request,
            'received',
            $this->normalizeInterestStatusFilter($request->query('status'))
        );
    }

    private function renderInterestsPage(Request $request, string $activeTab, string $statusFilter): View|RedirectResponse
    {
        $authUser = auth()->user();

        if (! $authUser || ! $authUser->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        $myProfileId = $authUser->matrimonyProfile->id;

        $sentInterestsFull = Interest::with('receiverProfile.gender')
            ->where('sender_profile_id', $myProfileId)
            ->latest()
            ->get();

        $receivedInterestsFull = Interest::with(ReceivedInterestTeaserBuilder::SENDER_PROFILE_EAGER_LOADS)
            ->where('receiver_profile_id', $myProfileId)
            ->receivedInboxOrder()
            ->get();

        $unlockById = $this->interestSendLimit->incomingInterestUnlockMap($authUser, $receivedInterestsFull);
        $interestViewLimit = $this->interestSendLimit->effectiveInterestViewLimit($authUser);
        $interestViewPeriod = $this->interestSendLimit->interestViewResetPeriodLabel($authUser);
        $interestViewWindowStart = $this->interestSendLimit->interestViewWindowStart($authUser);

        $receivedTeaserRaw = ReceivedInterestTeaserPolicy::normalized();
        $receivedInterestCardLayout = (string) ($receivedTeaserRaw['card_layout'] ?? 'horizontal');
        $applyRichReceivedLockedTeaser = ! empty($receivedTeaserRaw['rich_teaser_enabled']);
        $interestTeaserPlansUrl = route('plans.index');

        // Shared with the mobile inbox — see ReceivedInterestTeaserBuilder.
        $lockedInterestTeasers = $this->receivedTeasers->forLockedRows(
            $receivedInterestsFull,
            $unlockById,
            $authUser->matrimonyProfile,
            $receivedTeaserRaw,
        );

        $rowOrder = (string) ($receivedTeaserRaw['received_inbox_row_order'] ?? 'priority_then_recent');
        if (! in_array($rowOrder, ReceivedInterestTeaserPolicy::RECEIVED_INBOX_ROW_ORDERS, true)) {
            $rowOrder = 'priority_then_recent';
        }

        $inboxPerPage = (int) ($receivedTeaserRaw['received_inbox_per_page'] ?? 15);
        $inboxPerPage = max(5, min(50, $inboxPerPage));

        $receivedOrdered = $this->orderReceivedInboxForDisplay($receivedInterestsFull, $unlockById, $rowOrder);
        $receivedCounts = $this->interestStatusCounts($receivedOrdered);
        $sentCounts = $this->interestStatusCounts($sentInterestsFull);

        $pathParams = [];
        if ($activeTab === 'sent') {
            $pathParams['tab'] = 'sent';
        }
        if ($statusFilter !== 'all') {
            $pathParams['status'] = $statusFilter;
        }
        $hubPath = route('interests.index', $pathParams);

        $receivedFiltered = $this->filterInterestsByStatus($receivedOrdered, $statusFilter);
        $sentFiltered = $this->filterInterestsByStatus($sentInterestsFull, $statusFilter);

        $rpage = max(1, (int) $request->query('rpage', 1));
        $spage = max(1, (int) $request->query('spage', 1));

        $receivedTotal = $receivedFiltered->count();
        $receivedSlice = $receivedFiltered->forPage($rpage, $inboxPerPage)->values();
        $receivedInterests = new LengthAwarePaginator(
            $receivedSlice->all(),
            $receivedTotal,
            $inboxPerPage,
            $rpage,
            ['path' => $hubPath, 'pageName' => 'rpage']
        );

        $sentTotal = $sentFiltered->count();
        $sentSlice = $sentFiltered->forPage($spage, $inboxPerPage)->values();
        $sentInterests = new LengthAwarePaginator(
            $sentSlice->all(),
            $sentTotal,
            $inboxPerPage,
            $spage,
            ['path' => $hubPath, 'pageName' => 'spage']
        );

        // Same routed truth the mobile sent list gets, built by the same service:
        // an interest sitting with a Suchak must not read as a bare "pending".
        // Only the page being rendered is resolved, so the routing lookup never
        // runs for ordinary member-to-member rows on another page.
        $sentSuchakRouting = $this->routedInterests->sentListRoutingMap($sentSlice);

        return view('interests.index', compact(
            'activeTab',
            'statusFilter',
            'receivedCounts',
            'sentCounts',
            'sentInterests',
            'sentSuchakRouting',
            'receivedInterests',
            'unlockById',
            'interestViewLimit',
            'interestViewPeriod',
            'interestViewWindowStart',
            'applyRichReceivedLockedTeaser',
            'receivedInterestCardLayout',
            'lockedInterestTeasers',
            'interestTeaserPlansUrl',
        ));
    }

    private function normalizeInterestStatusFilter(mixed $raw): string
    {
        $s = strtolower(trim((string) $raw));

        return in_array($s, self::INTEREST_STATUS_FILTERS, true) ? $s : 'all';
    }

    /**
     * @param  Collection<int, Interest>  $interests
     * @return array{all: int, pending: int, accepted: int, rejected: int}
     */
    private function interestStatusCounts(Collection $interests): array
    {
        return [
            'all' => $interests->count(),
            'pending' => $interests->where('status', 'pending')->count(),
            'accepted' => $interests->where('status', 'accepted')->count(),
            'rejected' => $interests->where('status', 'rejected')->count(),
        ];
    }

    /**
     * @param  Collection<int, Interest>  $interests
     * @return Collection<int, Interest>
     */
    private function filterInterestsByStatus(Collection $interests, string $status): Collection
    {
        if ($status === 'all') {
            return $interests->values();
        }

        return $interests->where('status', $status)->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Interest>  $received
     * @param  array<int, bool>  $unlockById
     * @return \Illuminate\Support\Collection<int, Interest>
     */
    private function orderReceivedInboxForDisplay(Collection $received, array $unlockById, string $order): Collection
    {
        return match ($order) {
            'newest_first' => $received->sortByDesc('created_at')->values(),
            'unlocked_first_recent' => $received->sort(function (Interest $a, Interest $b) use ($unlockById) {
                $ua = ($unlockById[$a->id] ?? true) === true;
                $ub = ($unlockById[$b->id] ?? true) === true;
                if ($ua !== $ub) {
                    return $ua ? -1 : 1;
                }
                $cmp = $b->created_at <=> $a->created_at;

                return $cmp !== 0 ? $cmp : $a->id <=> $b->id;
            })->values(),
            default => $received->values(),
        };
    }

    /*
|--------------------------------------------------------------------------
| Accept Interest
|--------------------------------------------------------------------------
|
| 👉 Received interest accept करण्यासाठी
| 👉 Only receiver profile ला allow
|
*/
    public function accept(\App\Models\Interest $interest)
    {
        $user = auth()->user();

        // 🔒 Guard: login आवश्यक
        if (! $user) {
            abort(403);
        }

        $outcome = $this->interestActions->accept($user, $interest);

        if (! $outcome->ok) {
            return $this->renderRefusal($outcome);
        }

        return back()->with('success', $outcome->message());
    }

    /*
    |--------------------------------------------------------------------------
    | Reject Interest
    |--------------------------------------------------------------------------
    |
    | 👉 Received interest reject करण्यासाठी
    |
    */
    public function reject(\App\Models\Interest $interest)
    {
        $user = auth()->user();

        // 🔒 Guard: login आवश्यक
        if (! $user) {
            abort(403);
        }

        $outcome = $this->interestActions->reject($user, $interest);

        if (! $outcome->ok) {
            return $this->renderRefusal($outcome);
        }

        return back()->with('success', $outcome->message());
    }

    /*
    |--------------------------------------------------------------------------
    | Withdraw (Cancel) Interest
    |--------------------------------------------------------------------------
    |
    | 👉 Sender ला pending interest cancel करण्यासाठी
    |
    */
    public function withdraw(\App\Models\Interest $interest)
    {
        $user = auth()->user();

        // 🔒 Guard: login आवश्यक
        if (! $user) {
            abort(403);
        }

        $outcome = $this->interestActions->withdraw($user, $interest);

        if (! $outcome->ok) {
            return $this->renderRefusal($outcome);
        }

        return back()->with('success', $outcome->message());
    }
}
