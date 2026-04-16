@extends('layouts.admin-showcase')

@section('showcase_content')
{{-- Toggle Switch Styles --}}
<style>
.admin-toggle { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.admin-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.admin-toggle .toggle-track { width: 52px; height: 28px; background-color: #d1d5db; border-radius: 9999px; transition: background-color 0.2s ease; position: relative; }
.admin-toggle input:checked + .toggle-track { background-color: #10b981; }
.admin-toggle .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 24px; height: 24px; background-color: white; border-radius: 9999px; transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.admin-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(24px); }
.admin-toggle .toggle-label { margin-left: 12px; font-weight: 600; font-size: 14px; }
.admin-toggle .toggle-label.on { color: #059669; }
.admin-toggle .toggle-label.off { color: #6b7280; }
.dependent-fields { transition: opacity 0.2s ease; }
.dependent-fields.disabled { opacity: 0.5; pointer-events: none; }
</style>

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">View-Back Settings</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Control showcase → real view-back. Max one per showcase–real pair per 24 hours. No recursion.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-1">Showcase → Real view-back</p>
        <p>When a real user views a showcase profile, the system may create a view-back (showcase views real).</p>
    </div>
    <form method="POST" action="{{ route('admin.view-back-settings.update') }}" class="space-y-6">
        @csrf

        {{-- View-Back Toggle --}}
        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
            <label class="admin-toggle" id="viewBackToggle">
                <input type="checkbox" name="view_back_enabled" value="1" {{ $viewBackEnabled ? 'checked' : '' }} onchange="updateViewBackUI()">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label {{ $viewBackEnabled ? 'on' : 'off' }}" id="viewBackLabel">
                    {{ $viewBackEnabled ? 'View-Back is ENABLED' : 'View-Back is DISABLED' }}
                </span>
            </label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">When OFF, no showcase→real view-backs will be created regardless of other settings.</p>
        </div>

        {{-- Dependent Fields --}}
        <div id="viewBackDependentFields" class="dependent-fields {{ $viewBackEnabled ? '' : 'disabled' }} space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Probability (0–100%)</label>
                <input type="number" name="view_back_probability" min="0" max="100" value="{{ $viewBackProbability }}" required class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Chance of view-back triggering (0 = never, 100 = always).</p>
            </div>

            {{-- Delay settings --}}
            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">View-Back Delay</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Random delay before view-back is created. Leave at 0 for instant behavior.</p>
                <div class="flex gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Min delay (minutes)</label>
                        <input type="number" name="view_back_delay_min" min="0" max="1440" value="{{ $viewBackDelayMin }}" required class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max delay (minutes)</label>
                        <input type="number" name="view_back_delay_max" min="0" max="1440" value="{{ $viewBackDelayMax }}" required class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Actual delay = random value between min and max.</p>
            </div>
        </div>

        <div class="pt-6">
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                Save Settings
            </button>
        </div>
    </form>
</div>

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 mt-8 border border-violet-100 dark:border-violet-900/40">
    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1">Random profile views (showcase → real)</h2>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">Hourly scheduled job picks real members (opposite gender, active). Same religion / caste / district and nearby age increase weight; new accounts get a boost. Creates a normal <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">profile_views</code> row and the usual profile-viewed notification — appears in Who viewed me. Interests with that showcase are excluded.</p>

    <form method="POST" action="{{ route('admin.view-back-settings.random-views-update') }}" class="space-y-6">
        @csrf

        <div class="p-4 bg-violet-50 dark:bg-violet-950/30 rounded-lg border border-violet-200 dark:border-violet-800">
            <label class="admin-toggle">
                <input type="checkbox" name="showcase_random_view_enabled" value="1" {{ $randomViewEnabled ? 'checked' : '' }}>
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label {{ $randomViewEnabled ? 'on' : 'off' }}">
                    {{ $randomViewEnabled ? 'Random views ENABLED' : 'Random views DISABLED' }}
                </span>
            </label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Runs via <code class="text-xs">php artisan showcase:random-views</code> (scheduled hourly).</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Revisit gap (same showcase → same real)</label>
                <select name="showcase_random_view_revisit_mode" class="mt-1 w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm">
                    <option value="never" {{ $randomViewRevisitMode === 'never' ? 'selected' : '' }}>Never — one visit per pair (no repeat)</option>
                    <option value="1d" {{ $randomViewRevisitMode === '1d' ? 'selected' : '' }}>1 day</option>
                    <option value="7d" {{ $randomViewRevisitMode === '7d' ? 'selected' : '' }}>1 week</option>
                    <option value="30d" {{ $randomViewRevisitMode === '30d' ? 'selected' : '' }}>1 month (30 days)</option>
                    <option value="random" {{ $randomViewRevisitMode === 'random' ? 'selected' : '' }}>Random days (min–max below, rolled once per candidate check)</option>
                </select>
            </div>
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Random gap — min days</label>
                    <input type="number" name="showcase_random_view_revisit_random_min_days" min="1" max="365" value="{{ $randomViewRevisitRandomMinDays }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
                </div>
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Random gap — max days</label>
                    <input type="number" name="showcase_random_view_revisit_random_max_days" min="1" max="365" value="{{ $randomViewRevisitRandomMaxDays }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max views per run (all showcases)</label>
                <input type="number" name="showcase_random_view_batch_per_run" min="0" max="500" value="{{ $randomViewBatchPerRun }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">0 = none. One try per showcase per run until this cap.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Candidate pool size</label>
                <input type="number" name="showcase_random_view_candidate_pool" min="30" max="500" value="{{ $randomViewCandidatePool }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Age spread (years ± vs showcase)</label>
                <input type="number" name="showcase_random_view_age_spread_years" min="1" max="40" value="{{ $randomViewAgeSpreadYears }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cap per real — rolling 7 days</label>
                <input type="number" name="showcase_random_view_max_per_real_per_week" min="0" max="999" value="{{ $randomViewMaxPerRealWeek }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">From showcase profiles only. 0 = unlimited.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cap per real — rolling 30 days</label>
                <input type="number" name="showcase_random_view_max_per_real_per_month" min="0" max="999" value="{{ $randomViewMaxPerRealMonth }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">0 = unlimited.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">“New user” = registered within (days)</label>
                <input type="number" name="showcase_random_view_new_user_days" min="1" max="365" value="{{ $randomViewNewUserDays }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
            </div>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">Selection weights (higher = more likely)</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                @php
                    $w = [
                        'district' => $randomViewWeightDistrict,
                        'religion' => $randomViewWeightReligion,
                        'caste' => $randomViewWeightCaste,
                        'age' => $randomViewWeightAge,
                        'new_user' => $randomViewWeightNewUser,
                        'base' => $randomViewWeightBase,
                    ];
                @endphp
                @foreach ([
                    'district' => 'Same district',
                    'religion' => 'Same religion',
                    'caste' => 'Same caste',
                    'age' => 'Age within spread',
                    'new_user' => 'New user boost',
                    'base' => 'Base (everyone)',
                ] as $key => $label)
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ $label }}</label>
                        <input type="number" name="showcase_random_view_weight_{{ $key }}" min="0" max="500" value="{{ $w[$key] }}" required class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-2 py-1.5 text-sm">
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <button type="submit" class="rounded-lg bg-violet-600 hover:bg-violet-500 text-white px-4 py-2.5 text-sm font-semibold shadow">Save random view settings</button>
        </div>
    </form>
</div>

<script>
function updateViewBackUI() {
    const checkbox = document.querySelector('#viewBackToggle input');
    const label = document.getElementById('viewBackLabel');
    const dependentFields = document.getElementById('viewBackDependentFields');
    
    if (checkbox.checked) {
        label.textContent = 'View-Back is ENABLED';
        label.classList.remove('off');
        label.classList.add('on');
        dependentFields.classList.remove('disabled');
    } else {
        label.textContent = 'View-Back is DISABLED';
        label.classList.remove('on');
        label.classList.add('off');
        dependentFields.classList.add('disabled');
    }
}
</script>
@endsection
