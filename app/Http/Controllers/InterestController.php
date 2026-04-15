<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Notifications\InterestAcceptedNotification;
use App\Notifications\InterestRejectedNotification;
use App\Notifications\InterestSentNotification;
use App\Services\AdminActivityNotificationGate;
use App\Services\InterestPriorityService;
use App\Services\InterestSendLimitService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    public function __construct(
        private readonly InterestSendLimitService $interestSendLimit,
        private readonly InterestPriorityService $interestPriority,
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
        // 🔁 Internal SSOT variable alias
        $matrimonyProfile = $matrimony_profile_id;

        // 🔒 AUTH USER (authentication only)
        $authUser = auth()->user();

        // 🔒 GUARD: MatrimonyProfile must exist
        if (! $authUser || ! $authUser->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('interest.create_profile_first'));
        }

        // 🔒 Sender & Receiver Profiles (SSOT)
        $senderProfile = $authUser->matrimonyProfile;
        $receiverProfile = $matrimonyProfile;

        // 🔒 GUARD: Cannot send interest to own profile
        if ($senderProfile->id === $receiverProfile->id) {
            return back()->with(
                'error',
                __('interest.cannot_send_to_self')
            );
        }

        // 🔒 GUARD: Receiver has blocked sender → do not reveal
        if (Block::where('blocker_profile_id', $receiverProfile->id)->where('blocked_profile_id', $senderProfile->id)->exists()) {
            return back()->with('error', __('interest.cannot_send_to_profile'));
        }

        // 🔒 GUARD: Sender has blocked receiver
        if (Block::where('blocker_profile_id', $senderProfile->id)->where('blocked_profile_id', $receiverProfile->id)->exists()) {
            return back()->with('error', __('interest.blocked_unblock_to_send'));
        }

        // 🔒 Safety check (defensive)
        if (! $senderProfile || ! $receiverProfile) {
            abort(403, __('interest.create_profile_first'));
        }

        // Day 7: Sender lifecycle — Archived/Suspended/Search-Hidden cannot send interest
        if (! ProfileLifecycleService::canInitiateInteraction($senderProfile)) {
            return back()->with('error', __('interest.sender_cannot_send_current_state'));
        }

        // 🔒 70% completeness required for send and receive
        if (! ProfileCompletenessService::meetsThreshold($senderProfile)) {
            return back()->with('error', __('interest.sender_must_be_70_complete'));
        }
        if (! ProfileCompletenessService::meetsThreshold($receiverProfile)) {
            return back()->with('error', __('interest.cannot_send_to_profile'));
        }

        // Day 7: Archived/Suspended/Search-Hidden → interest blocked
        if (! ProfileLifecycleService::canReceiveInterest($receiverProfile)) {
            return back()->with('error', __('interest.cannot_send_to_profile'));
        }

        // Daily interest send quota via entitlements + user_feature_usages (new sends only)
        $alreadySent = Interest::where('sender_profile_id', $senderProfile->id)
            ->where('receiver_profile_id', $receiverProfile->id)
            ->exists();
        if (! $alreadySent) {
            try {
                $this->interestSendLimit->assertCanSend($authUser);
            } catch (HttpException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        // 🔁 Duplicate interest protection
        $interest = Interest::firstOrCreate(
            [
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $receiverProfile->id,
            ],
            [
                'status' => 'pending',
                'priority_score' => $this->interestPriority->baseScoreForSender($authUser),
            ]
        );

        if ($interest->wasRecentlyCreated) {
            $this->interestSendLimit->recordSuccessfulSend($authUser);
            $receiverOwner = $receiverProfile->user;
            if ($receiverOwner && AdminActivityNotificationGate::allowsPeerActivityNotification($authUser)) {
                $receiverOwner->notify(new InterestSentNotification($senderProfile));
            }
        }

        return back()->with('success', __('interest.interest_sent_successfully'));
    }

    /**
     * Interests hub: default tab is received; use ?tab=sent for sent.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $tab = strtolower((string) $request->query('tab', 'received')) === 'sent' ? 'sent' : 'received';
        $statusFilter = $this->normalizeInterestStatusFilter($request->query('status'));

        return $this->renderInterestsPage($tab, $statusFilter);
    }

    /*
    |--------------------------------------------------------------------------
    | Sent / Received (legacy URLs; same hub as {@see index})
    |--------------------------------------------------------------------------
    */
    public function sent(): View|RedirectResponse
    {
        return $this->renderInterestsPage(
            'sent',
            $this->normalizeInterestStatusFilter(request()->query('status'))
        );
    }

    public function received(): View|RedirectResponse
    {
        return $this->renderInterestsPage(
            'received',
            $this->normalizeInterestStatusFilter(request()->query('status'))
        );
    }

    private function renderInterestsPage(string $activeTab, string $statusFilter): View|RedirectResponse
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

        $receivedInterestsFull = Interest::with('senderProfile.gender')
            ->where('receiver_profile_id', $myProfileId)
            ->receivedInboxOrder()
            ->get();

        $unlockById = $this->interestSendLimit->incomingInterestUnlockMap($authUser, $receivedInterestsFull);
        $interestViewLimit = $this->interestSendLimit->effectiveInterestViewLimit($authUser);
        $interestViewPeriod = $this->interestSendLimit->interestViewResetPeriodLabel($authUser);
        $interestViewWindowStart = $this->interestSendLimit->interestViewWindowStart($authUser);

        $receivedCounts = $this->interestStatusCounts($receivedInterestsFull);
        $sentCounts = $this->interestStatusCounts($sentInterestsFull);

        $receivedInterests = $this->filterInterestsByStatus($receivedInterestsFull, $statusFilter);
        $sentInterests = $this->filterInterestsByStatus($sentInterestsFull, $statusFilter);

        return view('interests.index', compact(
            'activeTab',
            'statusFilter',
            'receivedCounts',
            'sentCounts',
            'sentInterests',
            'receivedInterests',
            'unlockById',
            'interestViewLimit',
            'interestViewPeriod',
            'interestViewWindowStart',
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
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        // 🔒 Guard: हा interest logged-in user चाच असला पाहिजे
        if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
            abort(403);
        }

        // 🔒 Guard: फक्त pending interest accept करता येईल
        if ($interest->status !== 'pending') {
            return back()->with('error', __('interest.interest_already_processed'));
        }

        // 🔒 70% completeness required to receive (accept) interest
        $receiverProfile = $interest->receiverProfile;
        if (! $receiverProfile || ! ProfileCompletenessService::meetsThreshold($receiverProfile)) {
            return back()->with('error', __('interest.receiver_must_be_70_complete_accept'));
        }

        // ✅ Accept
        $interest->update([
            'status' => 'accepted',
        ]);

        // Phase-5: Grant contact visibility via normalized table (replaces contact_visible_to JSON)
        $senderProfile = $interest->senderProfile;
        if ($senderProfile && $receiverProfile->contact_unlock_mode === 'after_interest_accepted') {
            \Illuminate\Support\Facades\DB::table('profile_contact_visibility')->insertOrIgnore([
                'owner_profile_id' => $receiverProfile->id,
                'viewer_profile_id' => $senderProfile->id,
                'granted_via' => 'interest_accept',
                'granted_at' => now(),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \Illuminate\Support\Facades\DB::table('contact_access_log')->insert([
                'owner_profile_id' => $receiverProfile->id,
                'viewer_profile_id' => $senderProfile->id,
                'source' => 'interest',
                'unlocked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $senderOwner = $interest->senderProfile?->user;
        if ($senderOwner && AdminActivityNotificationGate::allowsPeerActivityNotification($user)) {
            $senderOwner->notify(new InterestAcceptedNotification($receiverProfile));
        }

        return back()->with('success', __('interest.interest_accepted'));
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
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        // 🔒 Guard: हा interest logged-in user चाच असला पाहिजे
        if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
            abort(403);
        }

        // 🔒 Guard: फक्त pending interest reject करता येईल
        if ($interest->status !== 'pending') {
            return back()->with('error', __('interest.interest_already_processed'));
        }

        // ✅ Reject
        $interest->update([
            'status' => 'rejected',
        ]);

        $senderOwner = $interest->senderProfile?->user;
        if ($senderOwner && AdminActivityNotificationGate::allowsPeerActivityNotification($user)) {
            $senderOwner->notify(new InterestRejectedNotification($user->matrimonyProfile));
        }

        return back()->with('success', __('interest.interest_rejected'));
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

        // 🔒 Guard: login + profile आवश्यक
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        // 🔒 Guard: फक्त sender च withdraw करू शकतो
        if ($interest->sender_profile_id !== $user->matrimonyProfile->id) {
            abort(403);
        }

        // 🔒 Guard: फक्त pending interest withdraw करता येईल
        if ($interest->status !== 'pending') {
            return back()->with('error', __('interest.only_pending_withdraw'));
        }

        // ✅ Withdraw = delete record
        $interest->delete();

        return back()->with('success', __('interest.interest_withdrawn_successfully'));
    }
}
