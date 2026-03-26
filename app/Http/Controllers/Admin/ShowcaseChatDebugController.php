<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Services\ShowcaseChat\ShowcaseChatSettingsService;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;

class ShowcaseChatDebugController extends Controller
{
    public function __construct(
        protected ShowcaseOrchestrationService $orchestration,
        protected ShowcaseChatSettingsService $settingsService,
    ) {}

    public function show(Conversation $conversation)
    {
        $p1 = MatrimonyProfile::find($conversation->profile_one_id);
        $p2 = MatrimonyProfile::find($conversation->profile_two_id);
        $showcase = ($p1 && ($p1->is_demo ?? false)) ? $p1 : (($p2 && ($p2->is_demo ?? false)) ? $p2 : null);
        abort_unless($showcase !== null, 404);

        $other = ($showcase->id === $p1?->id) ? $p2 : $p1;

        $state = $this->orchestration->ensureState($conversation, $showcase);
        $setting = $this->settingsService->getOrCreateForProfile($showcase);
        $snapshot = $this->orchestration->buildDebugSnapshot($state, $setting);

        return view('admin.showcase-chat.debug', [
            'conversation' => $conversation,
            'showcase' => $showcase,
            'other' => $other,
            'snapshot' => $snapshot,
        ]);
    }
}
