<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatchBoostSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MatchBoostController extends Controller
{
    public function edit(): View
    {
        $settings = MatchBoostSetting::current();

        return view('admin.match-boost.edit', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'use_ai' => ['sometimes', 'boolean'],
            'ai_provider' => ['nullable', 'string', 'max:32'],
            'ai_model' => ['nullable', 'string', 'max:64'],
            'boost_active_weight' => ['required', 'integer', 'min:0', 'max:100'],
            'boost_premium_weight' => ['required', 'integer', 'min:0', 'max:100'],
            'boost_similarity_weight' => ['required', 'integer', 'min:0', 'max:100'],
            'max_boost_limit' => ['required', 'integer', 'min:0', 'max:100'],
            'boost_gold_extra' => ['required', 'integer', 'min:0', 'max:100'],
            'boost_silver_extra' => ['required', 'integer', 'min:0', 'max:100'],
            'active_within_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $settings = MatchBoostSetting::current();
        $settings->use_ai = $request->boolean('use_ai');
        $provider = isset($validated['ai_provider']) ? trim((string) $validated['ai_provider']) : '';
        if ($settings->use_ai) {
            $settings->ai_provider = $provider !== '' ? $provider : 'sarvam';
            if ($settings->ai_provider !== 'sarvam') {
                return redirect()
                    ->route('admin.match-boost.edit')
                    ->withErrors(['ai_provider' => __('match_boost.only_sarvam')]);
            }
        } else {
            $settings->ai_provider = null;
        }
        $settings->ai_model = $validated['ai_model'] ?? null;
        $settings->boost_active_weight = (int) $validated['boost_active_weight'];
        $settings->boost_premium_weight = (int) $validated['boost_premium_weight'];
        $settings->boost_similarity_weight = (int) $validated['boost_similarity_weight'];
        $settings->max_boost_limit = (int) $validated['max_boost_limit'];
        $settings->boost_gold_extra = (int) $validated['boost_gold_extra'];
        $settings->boost_silver_extra = (int) $validated['boost_silver_extra'];
        $settings->active_within_days = (int) $validated['active_within_days'];
        $settings->save();

        return redirect()
            ->route('admin.match-boost.edit')
            ->with('success', __('match_boost.saved'));
    }
}
