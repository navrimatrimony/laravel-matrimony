@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-3xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Who viewed — locked teaser cards</h1>
    <p class="text-gray-600 dark:text-gray-400 text-sm mb-6">
        Members without full identity for “who viewed me” see <strong>one card per viewer</strong> (paid members see full cards). Teaser fields use real profile data only — tune granularity to reduce re-identification risk in small areas.
        Settings are stored as JSON in <code class="text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">who_viewed_teaser_policy_json</code> (no new database columns).
    </p>

    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 text-sm mb-4">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('admin.who-viewed-teaser-settings.update') }}" class="space-y-6">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Location detail (never shows village / exact place)</label>
            <select name="location_granularity" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
                <option value="state_only" @selected(($policy['location_granularity'] ?? '') === 'state_only')>State only</option>
                <option value="district_and_above" @selected(($policy['location_granularity'] ?? '') === 'district_and_above')>District + state (recommended default)</option>
                <option value="taluka_and_above" @selected(($policy['location_granularity'] ?? '') === 'taluka_and_above')>Taluka + district + state</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Uses canonical <code class="text-xs">location_id</code> hierarchy only — leaf village name is never shown here.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Age on teaser</label>
            <select name="show_age_mode" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
                <option value="off" @selected(($policy['show_age_mode'] ?? '') === 'off')>Hidden</option>
                <option value="decade" @selected(($policy['show_age_mode'] ?? '') === 'decade')>Decade band (e.g. 20–29)</option>
                <option value="exact" @selected(($policy['show_age_mode'] ?? '') === 'exact')>Exact age (higher re-identification risk)</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Name</label>
            <select name="name_display" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
                <option value="hidden" @selected(($policy['name_display'] ?? '') === 'hidden')>Hidden (“Someone …”)</option>
                <option value="first_only" @selected(($policy['name_display'] ?? '') === 'first_only')>First name / first token only</option>
                <option value="full" @selected(($policy['name_display'] ?? '') === 'full')>Full name (highest risk)</option>
            </select>
        </div>

        <div class="space-y-3">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Optional detail lines</p>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="show_occupation" value="1" @checked(! empty($policy['show_occupation'])) class="rounded border-gray-300 dark:border-gray-600">
                Occupation
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="show_education" value="1" @checked(! empty($policy['show_education'])) class="rounded border-gray-300 dark:border-gray-600">
                Education (highest education text)
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="show_marital_status" value="1" @checked(! empty($policy['show_marital_status'])) class="rounded border-gray-300 dark:border-gray-600">
                Marital status label
            </label>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Max viewer cards (locked list + paid list cap)</label>
            <input type="number" name="locked_teaser_rows" min="1" max="60" value="{{ old('locked_teaser_rows', (int) ($policy['locked_teaser_rows'] ?? 40)) }}" class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Cap per page (1–60). Each distinct viewer is still one row; oldest beyond the cap are omitted.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Teaser avatar (identity-hidden cards)</label>
            <select name="teaser_avatar_style" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
                <option value="silhouette" @selected(($policy['teaser_avatar_style'] ?? '') === 'silhouette')>Silhouette icon only</option>
                <option value="blur" @selected(($policy['teaser_avatar_style'] ?? 'blur') === 'blur')>Blurred approved photo (stronger curiosity)</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">“Viewed …” time on teaser cards</label>
            <select name="teaser_viewed_time" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
                <option value="human" @selected(($policy['teaser_viewed_time'] ?? 'human') === 'human')>Relative time (e.g. 2 hours ago)</option>
                <option value="bucket" @selected(($policy['teaser_viewed_time'] ?? '') === 'bucket')>Coarse buckets (recent / this week / …)</option>
            </select>
        </div>

        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Save</button>
    </form>
</div>
@endsection
