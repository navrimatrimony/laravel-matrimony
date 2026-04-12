<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatchingBehaviorWeight;
use App\Models\MatchingBoostRule;
use App\Models\MatchingConfigVersion;
use App\Models\MatchingEngineConfig;
use App\Models\MatchingField;
use App\Models\MatchingHardFilter;
use App\Models\MatrimonyProfile;
use App\Services\Matching\MatchingAiSuggestionService;
use App\Services\Matching\MatchingConfigService;
use App\Services\Matching\MatchingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MatchingEngineController extends Controller
{
    public function __construct(
        protected MatchingConfigService $config,
        protected MatchingService $matching,
        protected MatchingAiSuggestionService $aiSuggestion,
    ) {}

    public function overview(): View
    {
        $this->config->ensureDefaults();
        $canEdit = $this->canEdit(request());
        $sum = $this->config->sumActiveFieldWeights();
        $runtime = MatchingEngineConfig::query()->where('config_key', 'runtime')->first();

        return view('admin.matching-engine.overview', [
            'canEdit' => $canEdit,
            'sumWeights' => $sum,
            'pool' => $this->config->candidatePoolLimit(),
            'persist' => $this->config->persistMatchesEnabled(),
            'runtimeRow' => $runtime,
        ]);
    }

    public function fields(): View
    {
        $this->config->ensureDefaults();
        $fields = MatchingField::query()->orderBy('id')->get();

        return view('admin.matching-engine.fields', [
            'canEdit' => $this->canEdit(request()),
            'fields' => $fields,
            'sumWeights' => $this->config->sumActiveFieldWeights(),
        ]);
    }

    public function saveFields(Request $request): RedirectResponse
    {
        $this->authorizeEdit($request);
        $this->config->ensureDefaults();

        $validated = $request->validate([
            'weights' => ['required', 'array'],
            'weights.*' => ['integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $sum = 0;
        foreach (MatchingField::query()->orderBy('id')->get() as $f) {
            $w = (int) ($validated['weights'][$f->field_key] ?? $f->weight);
            $on = $request->boolean('active.'.$f->field_key);
            if ($on) {
                $sum += max(0, $w);
            }
        }
        if ($sum < 1 || $sum > 100) {
            return back()->withErrors(['weights' => __('matching_engine.sum_weights_error', ['sum' => $sum])])->withInput();
        }

        foreach (MatchingField::query()->orderBy('id')->get() as $f) {
            $w = (int) ($validated['weights'][$f->field_key] ?? $f->weight);
            $w = min($f->max_weight, max(0, $w));
            $on = $request->boolean('active.'.$f->field_key);
            $f->update([
                'weight' => $w,
                'is_active' => $on,
            ]);
        }

        $this->saveVersion(__('matching_engine.nav_fields').' — '.$request->input('note', ''));
        $this->config->forgetCache();

        return redirect()->route('admin.matching-engine.fields')->with('success', __('matching_engine.saved'));
    }

    public function filters(): View
    {
        $this->config->ensureDefaults();
        $filters = MatchingHardFilter::query()->orderBy('filter_key')->get();

        return view('admin.matching-engine.filters', [
            'canEdit' => $this->canEdit(request()),
            'filters' => $filters,
        ]);
    }

    public function saveFilters(Request $request): RedirectResponse
    {
        $this->authorizeEdit($request);
        $this->config->ensureDefaults();

        $validated = $request->validate([
            'mode' => ['required', 'array'],
            'mode.*' => ['required', 'in:off,preferred,strict'],
            'penalty' => ['required', 'array'],
            'penalty.*' => ['integer', 'min:0', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (MatchingHardFilter::query()->get() as $f) {
            $f->update([
                'mode' => $validated['mode'][$f->filter_key] ?? $f->mode,
                'preferred_penalty_points' => (int) ($validated['penalty'][$f->filter_key] ?? $f->preferred_penalty_points),
            ]);
        }

        $this->saveVersion(__('matching_engine.nav_filters').' — '.$request->input('note', ''));
        $this->config->forgetCache();

        return redirect()->route('admin.matching-engine.filters')->with('success', __('matching_engine.saved'));
    }

    public function behavior(): View
    {
        $this->config->ensureDefaults();
        $rows = MatchingBehaviorWeight::query()->orderBy('action')->get();

        return view('admin.matching-engine.behavior', [
            'canEdit' => $this->canEdit(request()),
            'rows' => $rows,
            'behaviorCap' => $this->config->behaviorMaxPoints(),
        ]);
    }

    public function saveBehavior(Request $request): RedirectResponse
    {
        $this->authorizeEdit($request);
        $this->config->ensureDefaults();

        $validated = $request->validate([
            'weight' => ['required', 'array'],
            'weight.*' => ['integer', 'min:-30', 'max:30'],
            'decay' => ['required', 'array'],
            'decay.*' => ['integer', 'min:1', 'max:365'],
            'behavior_max_points' => ['nullable', 'integer', 'min:0', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (MatchingBehaviorWeight::query()->get() as $r) {
            $r->update([
                'weight' => (int) ($validated['weight'][$r->action] ?? $r->weight),
                'decay_days' => (int) ($validated['decay'][$r->action] ?? $r->decay_days),
                'is_active' => $request->boolean('active.'.$r->action),
            ]);
        }

        if ($request->filled('behavior_max_points')) {
            $this->updateRuntimeConfig(['behavior_max_points' => (int) $request->input('behavior_max_points')]);
        }

        $this->saveVersion(__('matching_engine.nav_behavior').' — '.$request->input('note', ''));
        $this->config->forgetCache();

        return redirect()->route('admin.matching-engine.behavior')->with('success', __('matching_engine.saved'));
    }

    public function boosts(): View
    {
        $this->config->ensureDefaults();
        $rules = MatchingBoostRule::query()->orderBy('boost_type')->get();

        return view('admin.matching-engine.boosts', [
            'canEdit' => $this->canEdit(request()),
            'rules' => $rules,
        ]);
    }

    public function saveBoosts(Request $request): RedirectResponse
    {
        $this->authorizeEdit($request);
        $this->config->ensureDefaults();

        $validated = $request->validate([
            'value' => ['required', 'array'],
            'value.*' => ['integer', 'min:0', 'max:100'],
            'max_cap' => ['required', 'array'],
            'max_cap.*' => ['integer', 'min:0', 'max:100'],
            'active_within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (MatchingBoostRule::query()->get() as $rule) {
            $meta = $rule->meta ?? [];
            if ($rule->boost_type === 'active' && $request->filled('active_within_days')) {
                $meta['active_within_days'] = (int) $request->input('active_within_days');
            }
            $rule->update([
                'value' => (int) ($validated['value'][$rule->boost_type] ?? $rule->value),
                'max_cap' => (int) ($validated['max_cap'][$rule->boost_type] ?? $rule->max_cap),
                'is_active' => $request->boolean('active.'.$rule->boost_type),
                'meta' => $meta,
            ]);
        }

        $this->saveVersion(__('matching_engine.nav_boosts').' — '.$request->input('note', ''));
        $this->config->forgetCache();

        return redirect()->route('admin.matching-engine.boosts')->with('success', __('matching_engine.saved'));
    }

    public function runtime(Request $request): RedirectResponse
    {
        $this->authorizeEdit($request);
        $this->config->ensureDefaults();

        $validated = $request->validate([
            'candidate_pool_limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'persist_cache_mode' => ['nullable', 'string', 'in:default,yes,no'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = [];
        if ($request->filled('candidate_pool_limit')) {
            $payload['candidate_pool_limit'] = (int) $validated['candidate_pool_limit'];
        } else {
            $payload['candidate_pool_limit'] = null;
        }
        $mode = (string) ($validated['persist_cache_mode'] ?? 'default');
        $payload['persist_cache'] = match ($mode) {
            'yes' => true,
            'no' => false,
            default => null,
        };
        $row = MatchingEngineConfig::query()->where('config_key', 'runtime')->first();
        if ($row) {
            $merged = array_merge($row->config_value ?? [], $payload);
            $row->update([
                'config_value' => $merged,
                'version' => (int) $row->version + 1,
                'created_by' => $request->user()->id,
            ]);
        }

        $this->saveVersion(__('matching_engine.runtime_heading').' — '.$request->input('note', ''));
        $this->config->forgetCache();

        return redirect()->route('admin.matching-engine.overview')->with('success', __('matching_engine.saved'));
    }

    public function ai(): View
    {
        $this->config->ensureDefaults();
        $payload = $this->aiSuggestion->suggest();

        return view('admin.matching-engine.ai', [
            'canEdit' => $this->canEdit(request()),
            'payload' => $payload,
        ]);
    }

    public function preview(Request $request): View
    {
        $this->config->ensureDefaults();
        $profileId = (int) $request->input('profile_id', 0);
        $rows = collect();
        $profile = null;
        if ($profileId > 0) {
            $profile = MatrimonyProfile::query()->find($profileId);
            if ($profile) {
                $rows = $this->matching->findMatches($profile, 15, true);
            }
        }

        return view('admin.matching-engine.preview', [
            'profileId' => $profileId,
            'profile' => $profile,
            'rows' => $rows,
        ]);
    }

    public function audit(): View
    {
        $this->config->ensureDefaults();
        $versions = MatchingConfigVersion::query()->orderByDesc('id')->limit(50)->get();

        return view('admin.matching-engine.audit', [
            'canEdit' => $this->canEdit(request()),
            'versions' => $versions,
        ]);
    }

    public function rollback(Request $request, MatchingConfigVersion $matching_config_version): RedirectResponse
    {
        $this->authorizeEdit($request);
        $snap = $matching_config_version->config_snapshot;
        if (! is_array($snap) || $snap === []) {
            return back()->withErrors(['rollback' => 'Invalid snapshot.']);
        }

        DB::transaction(function () use ($snap): void {
            $this->restoreSnapshot($snap);
        });

        $this->config->forgetCache();

        return redirect()->route('admin.matching-engine.audit')->with('success', __('matching_engine.rolled_back'));
    }

    private function saveVersion(string $note): void
    {
        MatchingConfigVersion::query()->create([
            'config_snapshot' => $this->config->captureSnapshotForVersioning(),
            'changed_by' => auth()->id(),
            'note' => mb_substr($note, 0, 500),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function restoreSnapshot(array $snap): void
    {
        MatchingField::query()->delete();
        foreach ($snap['fields'] ?? [] as $row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
            MatchingField::query()->create($row);
        }

        MatchingHardFilter::query()->delete();
        foreach ($snap['hard_filters'] ?? [] as $row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
            MatchingHardFilter::query()->create($row);
        }

        MatchingBehaviorWeight::query()->delete();
        foreach ($snap['behavior_weights'] ?? [] as $row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
            MatchingBehaviorWeight::query()->create($row);
        }

        MatchingBoostRule::query()->delete();
        foreach ($snap['boost_rules'] ?? [] as $row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
            MatchingBoostRule::query()->create($row);
        }

        MatchingEngineConfig::query()->delete();
        foreach ($snap['engine_configs'] ?? [] as $row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
            MatchingEngineConfig::query()->create($row);
        }
    }

    private function updateRuntimeConfig(array $merge): void
    {
        $row = MatchingEngineConfig::query()->where('config_key', 'runtime')->first();
        if (! $row) {
            return;
        }
        $row->update([
            'config_value' => array_merge($row->config_value ?? [], $merge),
            'version' => (int) $row->version + 1,
            'created_by' => auth()->id(),
        ]);
    }

    private function canEdit(Request $request): bool
    {
        $u = $request->user();
        if (! $u) {
            return false;
        }
        if ($u->isSuperAdmin() || $u->is_admin === true) {
            return true;
        }

        return $u->admin_role === 'data_admin';
    }

    private function authorizeEdit(Request $request): void
    {
        if (! $this->canEdit($request)) {
            abort(403, __('matching_engine.read_only'));
        }
    }
}
