<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Models\ShowcaseConversationState;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatMessageModerationService;
use App\Services\ShowcaseChat\ShowcaseAdminTakeoverService;
use App\Services\ShowcaseChat\ShowcaseChatSettingsService;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use App\Services\ShowcaseChat\ShowcaseReplyExecutionService;
use Illuminate\Http\Request;

class ShowcaseConversationController extends Controller
{
    public function __construct(
        protected ChatConversationService $conversations,
        protected ShowcaseOrchestrationService $orchestration,
        protected ShowcaseAdminTakeoverService $takeover,
        protected ShowcaseReplyExecutionService $replyExecutor,
        protected ShowcaseChatSettingsService $showcaseSettings,
    ) {}

    public function index()
    {
        $showcaseIds = MatrimonyProfile::query()->whereShowcase()->pluck('id')->all();
        $enabledIds = ShowcaseChatSetting::query()->where('enabled', true)->pluck('matrimony_profile_id')->all();
        $activeShowcaseIds = array_values(array_intersect($showcaseIds, $enabledIds));

        $conversations = Conversation::query()
            ->whereIn('profile_one_id', $activeShowcaseIds)
            ->orWhereIn('profile_two_id', $activeShowcaseIds)
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get();

        $profiles = MatrimonyProfile::query()
            ->whereIn('id', array_values(array_unique(array_merge(
                $conversations->pluck('profile_one_id')->all(),
                $conversations->pluck('profile_two_id')->all(),
            ))))
            ->get()
            ->keyBy('id');

        $states = ShowcaseConversationState::query()
            ->whereIn('conversation_id', $conversations->pluck('id')->all())
            ->get()
            ->groupBy('conversation_id');

        return view('admin.showcase-conversations.index', [
            'conversations' => $conversations,
            'profiles' => $profiles,
            'states' => $states,
        ]);
    }

    public function show(Conversation $conversation)
    {
        $p1 = MatrimonyProfile::find($conversation->profile_one_id);
        $p2 = MatrimonyProfile::find($conversation->profile_two_id);

        $showcase = ($p1 && $p1->isShowcaseProfile()) ? $p1 : (($p2 && $p2->isShowcaseProfile()) ? $p2 : null);
        abort_unless($showcase !== null, 404);

        $other = ($showcase->id === $p1?->id) ? $p2 : $p1;
        abort_unless($other !== null, 404);

        $setting = ShowcaseChatSetting::query()->where('matrimony_profile_id', $showcase->id)->first();
        $state = ShowcaseConversationState::query()
            ->where('conversation_id', $conversation->id)
            ->where('showcase_profile_id', $showcase->id)
            ->first();

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('sent_at', 'desc')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();
        $messages->loadMissing(['senderProfile']);

        $status = null;
        if ($setting && $setting->enabled) {
            $status = $this->orchestration->tickConversation($conversation, $showcase, $other);
        }

        $moderation = app(ChatMessageModerationService::class);
        $conversationHasFiltered = false;
        foreach ($messages as $m) {
            $text = (string) ($m->body_text ?? '');
            if ($text !== '' && $moderation->shouldMaskForDisplay($text)) {
                $conversationHasFiltered = true;
                break;
            }
        }

        return view('admin.showcase-conversations.show', [
            'conversation' => $conversation,
            'showcase' => $showcase,
            'other' => $other,
            'setting' => $setting,
            'state' => $state,
            'messages' => $messages,
            'presenceStatus' => $status,
            'conversationHasFiltered' => $conversationHasFiltered,
            'chatModeration' => $moderation,
        ]);
    }

    public function pause(Request $request, Conversation $conversation)
    {
        $showcaseId = (int) $request->input('showcase_profile_id');
        $showcase = MatrimonyProfile::findOrFail($showcaseId);
        abort_unless($showcase->isShowcaseProfile(), 404);

        $state = $this->orchestration->ensureState($conversation, $showcase);
        $this->takeover->pauseAutomationForConversation($state, $request->user(), 'Paused from admin conversation screen.');

        return back()->with('success', 'Automation paused (admin takeover).');
    }

    public function resume(Request $request, Conversation $conversation)
    {
        $showcaseId = (int) $request->input('showcase_profile_id');
        $showcase = MatrimonyProfile::findOrFail($showcaseId);
        abort_unless($showcase->isShowcaseProfile(), 404);

        $state = $this->orchestration->ensureState($conversation, $showcase);
        $this->takeover->resumeAutomationForConversation($state, $request->user(), 'Resumed from admin conversation screen.');

        return back()->with('success', 'Automation resumed.');
    }

    public function replyAsShowcase(Request $request, Conversation $conversation)
    {
        $request->validate([
            'showcase_profile_id' => 'required|integer',
            'body_text' => 'required|string|max:2000',
            'apply_personality_tone' => 'sometimes|boolean',
        ]);

        $showcase = MatrimonyProfile::findOrFail((int) $request->input('showcase_profile_id'));
        abort_unless($showcase->isShowcaseProfile(), 404);

        $other = $this->conversations->getOtherParticipant($conversation, $showcase);
        abort_unless($other !== null, 404);

        $state = $this->orchestration->ensureState($conversation, $showcase);
        $this->takeover->pauseAutomationForConversation($state, $request->user(), 'Admin replied as showcase.');

        $body = (string) $request->input('body_text');
        $setting = $this->showcaseSettings->getOrCreateForProfile($showcase);
        if ($request->boolean('apply_personality_tone')) {
            $body = $this->replyExecutor->applyToneToManualText($body, $setting, (int) $conversation->id);
        }

        $msg = $this->replyExecutor->sendShowcaseTextReply($showcase, $other, $conversation, $body);

        $this->orchestration->applyShowcaseOutgoingMessageEffects($conversation, $showcase, $msg, false);

        $state->refresh();
        $state->last_admin_reply_at = now();
        $state->save();

        return back()->with('success', 'Reply sent as showcase profile.');
    }
}

