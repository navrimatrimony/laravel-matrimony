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
use App\Services\CommunicationPolicyService;
use App\Services\ShowcaseChat\ShowcaseConversationTagService;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use App\Services\SubscriptionService;
use App\Services\UserEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChatController extends Controller
{
    public function __construct(
        protected ChatPolicyService $policy,
        protected ChatConversationService $conversations,
        protected ChatMessageService $messages,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $me = $user?->matrimonyProfile;
        if (! $me) {
            return redirect()->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('error', __('profile_actions.create_profile_first'));
        }

        if ($request->wantsJson() && $request->boolean('unread_only')) {
            $count = DB::table('messages')
                ->where('receiver_profile_id', $me->id)
                ->whereNull('read_at')
                ->count();

            return response()->json(['count' => (int) $count]);
        }

        $activeFilter = (string) $request->query('filter', 'all');
        if (! in_array($activeFilter, ['all', 'unread', 'awaiting_me', 'awaiting_them'], true)) {
            $activeFilter = 'all';
        }

        $list = $this->conversations->listConversationsForProfile($me);

        $conversationIds = $list->pluck('id')->all();
        $unreadByConversation = [];
        if (! empty($conversationIds)) {
            $unreadByConversation = DB::table('messages')
                ->select('conversation_id', DB::raw('COUNT(*) as c'))
                ->whereIn('conversation_id', $conversationIds)
                ->where('receiver_profile_id', $me->id)
                ->whereNull('read_at')
                ->groupBy('conversation_id')
                ->pluck('c', 'conversation_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        $lastMessageIds = $list->pluck('last_message_id')->filter()->unique()->values()->all();
        $lastMessagesById = [];
        if (! empty($lastMessageIds)) {
            $lastMessagesById = Message::query()
                ->whereIn('id', $lastMessageIds)
                ->get()
                ->keyBy('id')
                ->all();
        }

        $otherProfileIds = [];
        foreach ($list as $c) {
            $otherProfileIds[] = (int) ((int) $c->profile_one_id === (int) $me->id ? $c->profile_two_id : $c->profile_one_id);
        }
        $othersById = MatrimonyProfile::query()
            ->whereIn('id', array_values(array_unique($otherProfileIds)))
            ->get()
            ->keyBy('id');

        $items = $list->map(function (Conversation $c) use ($me, $unreadByConversation, $othersById, $lastMessagesById) {
            $otherId = (int) ((int) $c->profile_one_id === (int) $me->id ? $c->profile_two_id : $c->profile_one_id);
            /** @var MatrimonyProfile|null $other */
            $other = $othersById->get($otherId);
            $last = null;
            if ($c->last_message_id) {
                $last = $lastMessagesById[(int) $c->last_message_id] ?? null;
            }

            $preview = '';
            if ($last) {
                $preview = $this->previewLineForMessage($last, $me);
            }

            $awaitingMe = false;
            $awaitingThem = false;
            if ($last) {
                // "Awaiting my reply" should mean: the other participant spoke last (even if I've read it).
                $awaitingMe = ((int) $last->sender_profile_id !== (int) $me->id);
                $awaitingThem = ((int) $last->sender_profile_id === (int) $me->id);
            }

            return [
                'conversation' => $c,
                'other' => $other,
                'unread' => (int) ($unreadByConversation[$c->id] ?? 0),
                'last_message' => $last,
                'last_preview' => $preview,
                'awaiting_me' => $awaitingMe,
                'awaiting_them' => $awaitingThem,
                'showcase_tag' => ($other && ($other->is_demo ?? false))
                    ? app(ShowcaseConversationTagService::class)->shouldShowTagForConversation($c, $me, $other)
                    : false,
            ];
        });

        // Apply filter (no heavy SQL; use computed fields).
        $items = $items->filter(function ($it) use ($activeFilter) {
            if ($activeFilter === 'unread') {
                return (int) ($it['unread'] ?? 0) > 0;
            }
            if ($activeFilter === 'awaiting_me') {
                return (bool) ($it['awaiting_me'] ?? false);
            }
            if ($activeFilter === 'awaiting_them') {
                return (bool) ($it['awaiting_them'] ?? false);
            }

            return true;
        })->values();

        // Sorting: unread first, then latest activity.
        $items = $items->sort(function ($a, $b) {
            $ua = (int) ($a['unread'] ?? 0);
            $ub = (int) ($b['unread'] ?? 0);
            if (($ua > 0) !== ($ub > 0)) {
                return ($ua > 0) ? -1 : 1;
            }
            $ta = $a['conversation']->last_message_at?->getTimestamp() ?? 0;
            $tb = $b['conversation']->last_message_at?->getTimestamp() ?? 0;

            return $tb <=> $ta;
        })->values();

        return view('chat.index', [
            'me' => $me,
            'items' => $items,
            'activeFilter' => $activeFilter,
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
        if ($request->wantsJson()) {
            $q = Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderBy('id', 'asc');
            if ($sinceId > 0) {
                $q->where('id', '>', $sinceId);
            }
            $new = $q->limit(50)->get();
            $new->loadMissing(['senderProfile']);

            // Mark newly visible messages as read for current user.
            $this->messages->markConversationRead($me, $conversation);

            // Showcase demo: presence/typing ticks (orchestration). Admin debug & manual reply tone live under
            // admin routes (ShowcaseChatDebugController, ShowcaseConversationController::replyAsShowcase); not user-facing.
            $showcaseStatus = null;
            if (($other->is_demo ?? false) && $showcaseTag) {
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

        // Mark as read for current participant.
        $this->messages->markConversationRead($me, $conversation);

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
                $isPaid = $u && ($u->isAnyAdmin()
                    || UserEntitlementService::userHasEntitlement($u, UserEntitlementService::ENTITLEMENT_CHAT_IMAGE_MESSAGES)
                    || app(SubscriptionService::class)->canUseChatImages($u));
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

        try {
            app(SubscriptionService::class)->assertWithinChatSendLimit($user);
        } catch (HttpException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $this->messages->sendTextMessage($me, $other, $conversation, $body);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $msg = $errors['policy'][0] ?? $errors['body_text'][0] ?? $e->getMessage();

            return back()->with('error', $msg)->withErrors($errors);
        }

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
        $canImg = $u && ($u->isAnyAdmin()
            || UserEntitlementService::userHasEntitlement($u, UserEntitlementService::ENTITLEMENT_CHAT_IMAGE_MESSAGES)
            || app(SubscriptionService::class)->canUseChatImages($u));
        if (! $canImg) {
            return back()->with('error', __('subscriptions.feature_locked'));
        }

        try {
            $this->messages->sendImageMessage($me, $other, $conversation, $request->file('image'), $request->input('caption'));
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $msg = $errors['policy'][0] ?? $errors['image'][0] ?? $errors['caption'][0] ?? $e->getMessage();

            return back()->with('error', $msg)->withErrors($errors);
        }

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

        $this->messages->markConversationRead($me, $conversation);

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

    protected function previewLineForMessage(Message $last, MatrimonyProfile $me): string
    {
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
}
