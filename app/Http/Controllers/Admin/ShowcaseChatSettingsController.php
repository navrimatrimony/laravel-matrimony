<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\ShowcaseAdminAction;
use App\Models\ShowcaseChatSetting;
use App\Services\ShowcaseChat\ShowcaseChatSettingsService;
use Illuminate\Http\Request;

class ShowcaseChatSettingsController extends Controller
{
    public function __construct(
        protected ShowcaseChatSettingsService $settingsService,
    ) {}

    public function index()
    {
        $showcases = MatrimonyProfile::query()
            ->whereShowcase()
            ->orderByDesc('id')
            ->get(['id', 'full_name', 'lifecycle_state', 'is_showcase']);

        $settingsByProfileId = ShowcaseChatSetting::query()
            ->whereIn('matrimony_profile_id', $showcases->pluck('id')->all())
            ->get()
            ->keyBy('matrimony_profile_id');

        return view('admin.showcase-chat-settings.index', [
            'showcases' => $showcases,
            'settingsByProfileId' => $settingsByProfileId,
        ]);
    }

    public function show(MatrimonyProfile $profile)
    {
        abort_unless($profile->isShowcaseProfile(), 404);

        $s = $this->settingsService->getOrCreateForProfile($profile);

        return view('admin.showcase-chat-settings.show', [
            'profile' => $profile,
            'setting' => $s,
        ]);
    }

    public function update(Request $request, MatrimonyProfile $profile)
    {
        abort_unless($profile->isShowcaseProfile(), 404);

        $data = $request->validate([
            'enabled' => 'boolean',
            'ai_assisted_replies_enabled' => 'boolean',
            'admin_takeover_enabled' => 'boolean',
            'business_hours_enabled' => 'boolean',
            'business_days_json' => 'nullable|string',
            'business_hours_start' => 'nullable',
            'business_hours_end' => 'nullable',
            'off_hours_online_allowed' => 'boolean',
            'off_hours_read_allowed' => 'boolean',
            'off_hours_reply_allowed' => 'boolean',

            'online_session_min_minutes' => 'required|integer|min:1|max:240',
            'online_session_max_minutes' => 'required|integer|min:1|max:240',
            'offline_gap_min_minutes' => 'required|integer|min:0|max:1440',
            'offline_gap_max_minutes' => 'required|integer|min:0|max:1440',
            'online_before_read_min_seconds' => 'required|integer|min:1|max:600',
            'online_before_read_max_seconds' => 'required|integer|min:1|max:600',
            'online_linger_after_reply_min_seconds' => 'required|integer|min:1|max:3600',
            'online_linger_after_reply_max_seconds' => 'required|integer|min:1|max:3600',

            'read_delay_min_minutes' => 'required|integer|min:1|max:1440',
            'read_delay_max_minutes' => 'required|integer|min:1|max:1440',
            'read_only_when_online' => 'boolean',
            'force_read_by_max_hours' => 'nullable|integer|min:1|max:168',
            'batch_read_enabled' => 'boolean',
            'batch_read_window_min_minutes' => 'nullable|integer|min:1|max:1440',
            'batch_read_window_max_minutes' => 'nullable|integer|min:1|max:1440',

            'reply_delay_min_minutes' => 'required|integer|min:0|max:1440',
            'reply_delay_max_minutes' => 'required|integer|min:0|max:1440',
            'reply_after_read_min_minutes' => 'required|integer|min:0|max:1440',
            'reply_after_read_max_minutes' => 'required|integer|min:0|max:1440',
            'max_replies_per_day' => 'nullable|integer|min:0|max:1000',
            'max_replies_per_conversation_per_day' => 'nullable|integer|min:0|max:1000',
            'cooldown_after_last_outgoing_min_minutes' => 'nullable|integer|min:0|max:1440',
            'cooldown_after_last_outgoing_max_minutes' => 'nullable|integer|min:0|max:1440',

            'typing_enabled' => 'boolean',
            'typing_duration_min_seconds' => 'required|integer|min:1|max:120',
            'typing_duration_max_seconds' => 'required|integer|min:1|max:120',

            'reply_probability_percent' => 'required|integer|min:0|max:100',
            'initiate_probability_percent' => 'required|integer|min:0|max:100',

            'no_reply_after_unanswered_count' => 'nullable|integer|min:0|max:50',
            'pause_on_sensitive_keywords' => 'boolean',
            'is_paused' => 'boolean',

            'personality_preset' => 'required|string|in:warm,balanced,selective,reserved',
            'reply_length_min_words' => 'nullable|integer|min:1|max:200',
            'reply_length_max_words' => 'nullable|integer|min:1|max:200',
            'style_variation_enabled' => 'boolean',
        ]);

        $days = null;
        if (!empty($data['business_days_json'])) {
            $decoded = json_decode((string) $data['business_days_json'], true);
            if (is_array($decoded)) {
                $days = array_values(array_filter(array_map('intval', $decoded), fn ($d) => $d >= 1 && $d <= 7));
            }
        }
        $data['business_days_json'] = $days;

        $this->settingsService->validateTimingPairs($data);

        $s = $this->settingsService->getOrCreateForProfile($profile);
        $s->fill($data);
        $s->save();

        ShowcaseAdminAction::create([
            'admin_user_id' => auth()->id(),
            'showcase_profile_id' => $profile->id,
            'conversation_id' => null,
            'action_type' => 'settings_updated',
            'notes' => null,
        ]);

        return redirect()
            ->route('admin.showcase-chat-settings.show', ['profile' => $profile->id])
            ->with('success', 'Showcase chat settings updated.');
    }
}

