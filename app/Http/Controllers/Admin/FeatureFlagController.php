<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagAudit;
use App\Services\FeatureFlagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeatureFlagController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlags,
    ) {}

    public function index(): View
    {
        $flags = $this->featureFlags->all();
        $audits = FeatureFlagAudit::query()
            ->with(['changedByUser', 'featureFlag'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('admin.feature-flags.index', [
            'flags' => $flags,
            'audits' => $audits,
            'canModify' => (bool) request()->user()?->isSuperAdmin(),
        ]);
    }

    public function update(Request $request, FeatureFlag $featureFlag): RedirectResponse
    {
        $admin = $request->user();
        if (! $admin || ! $admin->isSuperAdmin()) {
            abort(403, 'Only Super Admin may change feature flags.');
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $enabled = $request->boolean('enabled');

        $this->featureFlags->setEnabled(
            $featureFlag->key,
            $enabled,
            $admin,
            $validated['reason'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        $state = $enabled ? 'enabled' : 'disabled';

        return redirect()
            ->route('admin.feature-flags.index')
            ->with('success', "{$featureFlag->display_name} has been {$state}.");
    }
}
