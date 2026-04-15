<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatMessageModerationService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPolicyService;
use App\Services\Chat\ChatTemplateSuggestionService;
use App\Services\ChatListService;
use App\Services\CommunicationPolicyService;
use App\Services\FeatureUsageService;
use App\Services\ShowcaseChat\ShowcaseConversationTagService;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use App\Services\UserEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function __construct(
        protected ChatPolicyService $policy,
        protected ChatConversationService $conversations,
        protected ChatMessageService $messages,
        protected FeatureUsageService $featureUsage,
        protected ChatListService $chatList,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('profile_actions.create_profile_first'));
        }

        // Legacy polling endpoint (kept): unread message count.
        if ($request->wantsJson() && $request->boolean('unread_only')) {
            return response()->json([
                'count' => $this->chatList->getUnreadMessageCount((int) $me->id),
            ]);
        }

        $tab = (string) $request->query('tab', 'all');
        if (! in_array($tab, ['all', 'unread', 'requests'], true)) {
            $tab = 'all';
        }

        $all = $this->chatList->getAllConversations((int) $me->id);
        $unread = $this->chatList->getUnreadConversations((int) $me->id);
        $requests = $this->chatList->getRequestConversations((int) $me->id);

        $conversations = match ($tab) {
            'unread' => $unread,
            'requests' => $requests,
            default => $all,
        };

        return view('chat.index', [
            'me' => $me,
            'tab' => $tab,
            'conversations' => $conversations,
            'counts' => [
                'all' => $all->count(),
                'unread' => $unread->count(),
                'requests' => $requests->count(),
            ],
        ]);
    }

    public function start(Request $request, MatrimonyProfile $matrimony_profile)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            abort(403);
        }

        $receiver = $matrimony_profile;
        $decision = $this->policy->canStartConversation($me, $receiver);
        if (! $decision->allowed) {
            return back()->with('error', $decision->humanMessage);
        }

        $conversation = $this->conversations->findOrCreateConversationBetweenProfiles($me, $receiver);

        return redirect()->route('chat.show', ['conversation' => $conversation->id]);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            abort(403);
        }

        if (! in_array((int) $me->id, [(int) $conversation->profile_one_id, (int) $conversation->profile_two_id], true)) {
            abort(403);
        }

        $other = $this->conversations->getOtherParticipant($conversation, $me);
        if (! $other) {
            abort(404);
        }

        $showcaseTag = app(ShowcaseConversationTagService::class)->shouldShowTagForConversation($conversation, $me, $other);

        $sinceId = $request->wantsJson() ? (int) $request->query('since_id', 0) : 0;
        $readLockedForIncoming = ! $this->featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_CAN_READ);

        if ($request->wantsJson()) {
            $q = Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderBy('id', 'asc');
            if ($sinceId > 0) {
                $q->where('id', '>', $sinceId);
            }
            $new = $q->limit(50)->get();
            $new->loadMissing(['senderProfile']);

            if (! $readLockedForIncoming) {
                $this->messages->markConversationRead($me, $conversation);
            }

            // Showcase automation: presence/typing ticks (orchestration). Admin debug & manual reply tone live under
            // admin routes (ShowcaseChatDebugController, ShowcaseConversationController::replyAsShowcase); not user-facing.
            $showcaseStatus = null;
            if ($other->isShowcaseProfile() && $showcaseTag) {
                try {
                    $showcaseStatus = app(ShowcaseOrchestrationService::class)->tickConversation($conversation, $other, $me);
                } catch (\Throwable $e) {
                    $showcaseStatus = ['online' => false, 'typing' => false];
                }
            }

            $html = [];
            $messageIds = [];
            foreach ($new as $m) {
                $messageIds[] = (int) $m->id;
                $html[] = view('chat.partials.message-bubble', [
                    'message' => $m,
                    'isMine' => ((int) $m->sender_profile_id === (int) $me->id),
                    'senderPhotoUrl' => $m->senderProfile?->profile_photo_url,
                    'viewerProfileId' => (int) $me->id,
                    'readLockedForIncoming' => $readLockedForIncoming,
                ])->render();
            }

            return response()->json([
                'count' => $new->count(),
                'last_id' => $new->last()?->id,
                'message_ids' => $messageIds,
                'html' => $html,
                'showcase' => $showcaseStatus,
            ]);
        }

        $page = $this->messages->getMessagesPaginated($conversation, 30);
        $messages = $page->getCollection()->reverse()->values(); // oldest->newest for UI
        $messages->loadMissing(['senderProfile']);

        if (! $readLockedForIncoming) {
            $this->messages->markConversationRead($me, $conversation);
        }

        $canSend = $this->policy->canSendMessage($me, $other, $conversation);

        $cfg = CommunicationPolicyService::getConfig();
        $imagePolicy = [
            'allowed' => true,
            'reason' => '',
        ];
        if (! ($cfg['allow_image_messages'] ?? true)) {
            $imagePolicy = [
                'allowed' => false,
                'reason' => 'Image messages are disabled by admin policy.',
            ];
        } else {
            $audience = (string) ($cfg['image_messages_audience'] ?? 'paid_only');
            if ($audience === 'paid_only') {
                $u = $user;
                $isPaid = $u && ($this->featureUsage->shouldBypassUsageLimits($u)
                    || UserEntitlementService::userHasEntitlement($u, UserEntitlementService::ENTITLEMENT_CHAT_IMAGE_MESSAGES)
                    || $this->featureUsage->subscriptionAllowsChatImages($u));
                if (! $isPaid) {
                    $imagePolicy = [
                        'allowed' => false,
                        'reason' => 'Image messages are locked for free users.',
                    ];
                }
            }
        }

        $chatTemplateSuggestions = app(ChatTemplateSuggestionService::class)
            ->getSuggestionGroupsForConversation($other);

        return view('chat.show', [
            'me' => $me,
            'viewerProfileId' => (int) $me->id,
            'conversation' => $conversation,
            'other' => $other,
            'messages' => $messages,
            'paginator' => $page,
            'canSendDecision' => $canSend,
            'imagePolicy' => $imagePolicy,
            'showcaseTag' => $showcaseTag,
            'chatTemplateSuggestions' => $chatTemplateSuggestions,
            'readLockedForIncoming' => $readLockedForIncoming,
        ]);
    }

    public function sendText(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            abort(403);
        }

        if (! in_array((int) $me->id, [(int) $conversation->profile_one_id, (int) $conversation->profile_two_id], true)) {
            abort(403);
        }

        $other = $this->conversations->getOtherParticipant($conversation, $me);
        if (! $other) {
            abort(404);
        }

        $request->validate([
            'body_text' => 'required|string|max:2000',
        ]);

        $body = (string) $request->input('body_text');
        $mod = app(ChatMessageModerationService::class);
        $decision = $mod->moderate($body);
        if (in_array($decision['severity'], ['block', 'warn'], true)) {
            throw ValidationException::withMessages([
                'body_text' => $decision['user_safe_message'],
            ]);
        }

        $uid = (int) $user->id;
        $canSend = $this->featureUsage->canUse($uid, FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)
            || $this->featureUsage->canSendChatInExistingConversation($uid, (int) $conversation->id, (int) $me->id);
        if (! $canSend) {
            return $this->chatSendLimitExceededResponse($request);
        }

        try {
            $this->messages->sendTextMessage($me, $other, $conversation, $body);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $msg = $errors['policy'][0] ?? $errors['body_text'][0] ?? $e->getMessage();

            return back()->with('error', $msg)->withErrors($errors);
        }

        $this->featureUsage->consumeChatSendAfterMessage($uid, (int) $conversation->id, (int) $me->id);

        return back();
    }

    public function sendImage(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            abort(403);
        }

        if (! in_array((int) $me->id, [(int) $conversation->profile_one_id, (int) $conversation->profile_two_id], true)) {
            abort(403);
        }

        $other = $this->conversations->getOtherParticipant($conversation, $me);
        if (! $other) {
            abort(404);
        }

        $request->validate([
            'image' => 'required|file|image|max:4096',
            'caption' => 'nullable|string|max:500',
        ]);

        $caption = trim((string) $request->input('caption'));
        if ($caption !== '') {
            $mod = app(ChatMessageModerationService::class);
            $decision = $mod->moderate($caption);
            if (in_array($decision['severity'], ['block', 'warn'], true)) {
                throw ValidationException::withMessages([
                    'caption' => $decision['user_safe_message'],
                ]);
            }
        }

        $u = $user;
        $canImg = $u && ($this->featureUsage->shouldBypassUsageLimits($u)
            || UserEntitlementService::userHasEntitlement($u, UserEntitlementService::ENTITLEMENT_CHAT_IMAGE_MESSAGES)
            || $this->featureUsage->subscriptionAllowsChatImages($u));
        if (! $canImg) {
            return back()->with('error', __('subscriptions.feature_locked'));
        }

        $uid = (int) $user->id;
        $canSend = $this->featureUsage->canUse($uid, FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)
            || $this->featureUsage->canSendChatInExistingConversation($uid, (int) $conversation->id, (int) $me->id);
        if (! $canSend) {
            return $this->chatSendLimitExceededResponse($request);
        }

        try {
            $this->messages->sendImageMessage($me, $other, $conversation, $request->file('image'), $request->input('caption'));
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $msg = $errors['policy'][0] ?? $errors['image'][0] ?? $errors['caption'][0] ?? $e->getMessage();

            return back()->with('error', $msg)->withErrors($errors);
        }

        $this->featureUsage->consumeChatSendAfterMessage($uid, (int) $conversation->id, (int) $me->id);

        return back();
    }

    public function read(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            abort(403);
        }

        if (! in_array((int) $me->id, [(int) $conversation->profile_one_id, (int) $conversation->profile_two_id], true)) {
            abort(403);
        }

        if ($this->featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_CAN_READ)) {
            $this->messages->markConversationRead($me, $conversation);
        }

        return back();
    }

    public function image(Request $request, Message $message)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            abort(403);
        }

        return $this->messages->streamChatImage($me, $message);
    }

    // unread counts handled via grouped query in index()

    protected function previewLineForMessage(Message $last, MatrimonyProfile $me, bool $viewerCanReadIncoming = true): string
    {
        if ((int) $last->sender_profile_id !== (int) $me->id && ! $viewerCanReadIncoming) {
            return (string) __('chat_ui.read_locked_preview');
        }

        $mod = app(ChatMessageModerationService::class);
        if (($last->message_type ?? Message::TYPE_TEXT) === Message::TYPE_IMAGE) {
            $caption = trim((string) ($last->body_text ?? ''));
            if ($caption === '') {
                return '📷 Image';
            }
            $disp = $mod->bodyTextForViewer($last, (int) $me->id, false);

            return '📷 '.$disp['text'];
        }

        $disp = $mod->bodyTextForViewer($last, (int) $me->id, false);

        return (string) ($disp['text'] ?? '');
    }

    private function chatSendLimitExceededResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => __('subscriptions.chat_limit_json'),
            ], 403);
        }

        return back()
            ->with('error', __('subscriptions.chat_limit_flash'))
            ->with('chat_upgrade_cta', true);
    }
}
