@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-2xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Moderation engine settings</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
        These values are stored for a future sync with the Python NudeNet service. Changing them does not alter live scanner behaviour yet.
        Engine version label for overrides: <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">{{ config('moderation.engine_version_label') }}</code>
        (<code class="text-xs">PHOTO_MODERATION_ENGINE_VERSION</code> / <code class="text-xs">config/moderation.php</code>).
    </p>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100">{{ session('success') }}</div>
    @endif

    <form method="post" action="{{ route('admin.moderation-engine-settings.update') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">nsfw_score_min (stored key: moderation_nsfw_score_min)</label>
            <input type="text" name="moderation_nsfw_score_min" value="{{ old('moderation_nsfw_score_min', $nsfwScoreMin) }}" class="mt-1 w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-2 text-sm" placeholder="e.g. 0.6">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">review_score_min (stored key: moderation_review_score_min)</label>
            <input type="text" name="moderation_review_score_min" value="{{ old('moderation_review_score_min', $reviewScoreMin) }}" class="mt-1 w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-2 text-sm" placeholder="e.g. 0.4">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">ignore_classes (comma or newline separated → JSON array)</label>
            <textarea name="moderation_ignore_classes" rows="4" class="mt-1 w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-2 text-sm font-mono" placeholder="CLASS_A, CLASS_B">{{ old('moderation_ignore_classes', $ignoreClassesCsv) }}</textarea>
        </div>
        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700 text-sm font-medium">Save</button>
    </form>
</div>
@endsection
