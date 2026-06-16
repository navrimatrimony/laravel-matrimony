<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\AdminSetting;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ProfileView;
use App\Models\Shortlist;
use App\Services\Interest\ReceivedInterestTeaserPolicy;
use App\Services\InterestSendLimitService;
use App\Services\NudgeService;
use App\Services\ProfileCompletionEngine;
use App\Services\ReferralService;
use App\Services\RecommendationService;
use App\Services\SubscriptionService;
use App\Services\UserWalletService;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| DashboardController
|--------------------------------------------------------------------------
|
| User dashboard data preparation.
| All queries moved from Blade view to controller (SSOT refactor).
|
*/

class DashboardController extends Controller
{
    public function __construct(
        private readonly ProfileCompletionEngine $profileCompletionEngine,
        private readonly RecommendationService $recommendationService,
        private readonly NudgeService $nudgeService,
        private readonly InterestSendLimitService $interestSendLimit,
        private readonly WhoViewedTeaserPresenter $whoViewedTeaserPresenter,
        private readonly ReferralService $referralService,
    ) {}

    /**
     * Show user dashboard.
     */
    public function index()
    {
        $user = auth()->user();
        if ($user?->suchakAccount()->exists()) {
            return redirect()->route('suchak.dashboard');
        }

        $referralShareTools = $this->referralService->shareToolsForReferrer($user);
        $referralShareUrl = $referralShareTools['share_url'] ?? null;
        $referralSummary = $this->referralService->summaryForReferrer($user);

        // No profile case - view handles this with empty data
        if (! $user->matrimonyProfile) {
            return view('dashboard', [
                'hasProfile' => false,
                'recommendations' => [],
                'nudges' => [],
                'referredRegistrationWelcome' => $this->referralService->registrationWelcomeBanner($user),
                'referralShareTools' => $referralShareTools,
                'referralShareUrl' => $referralShareUrl,
                'referralSummary' => $referralSummary,
            ]);
        }

        $profile = $user->matrimonyProfile->load(['gender', 'city', 'state']);
        $myProfileId = $profile->id;

        // Statistics
        $sentInterestsCount = Interest::where('sender_profile_id', $myProfileId)->count();
        $receivedPendingCount = Interest::where('receiver_profile_id', $myProfileId)
            ->where('status', 'pending')
            ->count();
        $acceptedInterestsCount = Interest::where('receiver_profile_id', $myProfileId)
            ->where('status', 'accepted')
            ->count();
        $rejectedInterestsCount = Interest::where('receiver_profile_id', $myProfileId)
            ->where('status', 'rejected')
            ->count();
        $totalProfilesCount = MatrimonyProfile::where('id', '!=', $myProfileId)->count();
        $shortlistCount = Shortlist::where('owner_profile_id', $myProfileId)->count();
        $mobileVerified = (bool) $user->mobile_verified_at;

        $completion = $this->profileCompletionEngine->for($user);

        // Recent Interests (Last 3 received)
        $recentReceivedInterests = Interest::with('senderProfile.gender')
            ->where('receiver_profile_id', $myProfileId)
            ->receivedInboxOrder()
            ->limit(3)
            ->get();
        $recentReceivedUnlockById = $this->interestSendLimit->incomingInterestUnlockMap($user, $recentReceivedInterests);
        $receivedTeaserPolicy = ReceivedInterestTeaserPolicy::forLockedPresentation(ReceivedInterestTeaserPolicy::normalized());
        $recentReceivedLockedTeasers = [];
        foreach ($recentReceivedInterests as $interestRow) {
            if (($recentReceivedUnlockById[$interestRow->id] ?? true) === true) {
                continue;
            }
            $senderProfile = $interestRow->senderProfile;
            if ($senderProfile === null) {
                continue;
            }
            $recentReceivedLockedTeasers[$interestRow->id] = $this->whoViewedTeaserPresenter->presentFromMatrimonyProfile(
                $senderProfile,
                $interestRow->created_at,
                $receivedTeaserPolicy,
                [
                    'owner_profile' => $profile,
                    'viewer_view_count' => 1,
                    'teaser_time_line' => 'interest_received',
                ]
            );
        }

        // Recent Sent Interests (Last 3)
        $recentSentInterests = Interest::with('receiverProfile.gender')
            ->where('sender_profile_id', $myProfileId)
            ->latest()
            ->limit(3)
            ->get();

        // Chat widget: unread count + top 3 recent unread conversations (lightweight)
        $chatUnreadCount = DB::table('messages')
            ->where('receiver_profile_id', $myProfileId)
            ->whereNull('read_at')
            ->count();

        $recentUnread = collect();
        if ($chatUnreadCount > 0) {
            $rows = DB::table('messages')
                ->select('conversation_id', DB::raw('MAX(id) as last_unread_id'), DB::raw('MAX(sent_at) as last_unread_at'))
                ->where('receiver_profile_id', $myProfileId)
                ->whereNull('read_at')
                ->groupBy('conversation_id')
                ->orderByDesc('last_unread_at')
                ->limit(3)
                ->get();

            $messageIds = $rows->pluck('last_unread_id')->filter()->values()->all();
            $messagesById = Message::query()->whereIn('id', $messageIds)->get()->keyBy('id');

            $conversationIds = $rows->pluck('conversation_id')->values()->all();
            $conversationsById = Conversation::query()->whereIn('id', $conversationIds)->get()->keyBy('id');

            $otherIds = [];
            foreach ($conversationsById as $c) {
                $otherIds[] = (int) ((int) $c->profile_one_id === (int) $myProfileId ? $c->profile_two_id : $c->profile_one_id);
            }
            $othersById = MatrimonyProfile::query()->whereIn('id', array_values(array_unique($otherIds)))->get()->keyBy('id');

            $recentUnread = $rows->map(function ($r) use ($myProfileId, $messagesById, $conversationsById, $othersById) {
                $c = $conversationsById->get((int) $r->conversation_id);
                $m = $messagesById->get((int) $r->last_unread_id);
                if (! $c || ! $m) {
                    return null;
                }
                $otherId = (int) ((int) $c->profile_one_id === (int) $myProfileId ? $c->profile_two_id : $c->profile_one_id);
                $other = $othersById->get($otherId);

                $preview = '';
                if (($m->message_type ?? 'text') === 'image') {
                    $preview = ($m->body_text ?? '') !== '' ? ('📷 '.$m->body_text) : '📷 Image';
                } else {
                    $preview = (string) ($m->body_text ?? '');
                }

                return [
                    'conversation' => $c,
                    'other' => $other,
                    'preview' => $preview,
                    'sent_at' => $m->sent_at,
                ];
            })->filter()->values();
        }

        $walletSummary = app(UserWalletService::class)->walletSummary($user);
        $activeSub = app(SubscriptionService::class)->getActiveSubscription($user);
        $planExpiresInDays = null;
        if ($activeSub && $activeSub->ends_at && $activeSub->ends_at->isFuture()) {
            $planExpiresInDays = max(0, (int) now()->diffInDays($activeSub->ends_at, false));
        }
        $profileViewersRecentCount = (int) ProfileView::query()
            ->where('viewed_profile_id', $myProfileId)
            ->where('created_at', '>=', now()->subDays(30))
            ->pluck('viewer_profile_id')
            ->unique()
            ->count();

        $referralPendingClaimCount = (int) ($referralSummary['pending_claim'] ?? 0);

        $recommendations = $this->recommendationService->getTopMatches($user, 10);
        $nudges = $this->nudgeService->getNudges($user, $recommendations);
        $notificationCardsLimit = max(1, min(3, (int) AdminSetting::getValue('dashboard_notification_cards_limit', '2')));
        $activityAutoHideSeconds = max(3, min(30, (int) AdminSetting::getValue('dashboard_activity_autohide_seconds', '7')));

        return view('dashboard', [
            'hasProfile' => true,
            'profile' => $profile,
            'myProfileId' => $myProfileId,
            'walletSummary' => $walletSummary,
            'planExpiresInDays' => $planExpiresInDays,
            'profileViewersRecentCount' => $profileViewersRecentCount,
            'referralShareUrl' => $referralShareUrl,
            'referralShareTools' => $referralShareTools,
            'referralSummary' => $referralSummary,
            'referralPendingClaimCount' => $referralPendingClaimCount,
            'referredRegistrationWelcome' => $this->referralService->registrationWelcomeBanner($user),
            'sentInterestsCount' => $sentInterestsCount,
            'receivedPendingCount' => $receivedPendingCount,
            'acceptedInterestsCount' => $acceptedInterestsCount,
            'rejectedInterestsCount' => $rejectedInterestsCount,
            'totalProfilesCount' => $totalProfilesCount,
            'shortlistCount' => $shortlistCount,
            'mobileVerified' => $mobileVerified,
            'completion' => $completion,
            'recentReceivedInterests' => $recentReceivedInterests,
            'recentReceivedUnlockById' => $recentReceivedUnlockById,
            'recentReceivedLockedTeasers' => $recentReceivedLockedTeasers,
            'recentSentInterests' => $recentSentInterests,
            'chatUnreadCount' => (int) $chatUnreadCount,
            'recentUnreadChats' => $recentUnread,
            'recommendations' => $recommendations,
            'nudges' => $nudges,
            'notificationCardsLimit' => $notificationCardsLimit,
            'activityAutoHideSeconds' => $activityAutoHideSeconds,
        ]);
    }
}
