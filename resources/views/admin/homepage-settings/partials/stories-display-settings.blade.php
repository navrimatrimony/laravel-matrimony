@php
    $display = old('success_stories_display', $settings['success_stories_display'] ?? 'slider');
    $bool = fn (string $key, bool $default = true): bool => (bool) old($key, $settings[$key] ?? $default);
@endphp

<div class="rounded-lg border border-indigo-100 bg-indigo-50/40 p-4 shadow-sm dark:border-indigo-900/50 dark:bg-indigo-950/20">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Display & slider</h2>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Couple photos, slider, and limits. Title/intro on <strong>Content</strong> tab. The whole यशोगाथा block is <strong>hidden on the homepage</strong> until at least one story is published (no empty placeholder). Toggle the section under <strong>Controls</strong>.</p>
        </div>
        <a href="{{ url('/') }}#success-stories" target="_blank" rel="noopener" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-indigo-700 hover:underline dark:text-indigo-300">Preview on homepage</a>
    </div>

    <form method="POST" action="{{ route('admin.homepage-settings.stories-display.update') }}" class="mt-4 space-y-4">
        @csrf

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Layout on homepage</label>
                <select name="success_stories_display" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="grid" @selected($display === 'grid')>Grid (static cards)</option>
                    <option value="slider" @selected($display === 'slider')>Slider / carousel</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Stories shown (max)</label>
                <input type="number" min="1" max="24" name="story_limit" value="{{ old('story_limit', $settings['story_limit'] ?? 6) }}" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Autoplay interval (seconds)</label>
                <input type="number" min="2" max="30" name="success_stories_autoplay_seconds" value="{{ old('success_stories_autoplay_seconds', $settings['success_stories_autoplay_seconds'] ?? 5) }}" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="flex flex-col justify-end gap-2">
                <input type="hidden" name="success_stories_autoplay" value="0">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="success_stories_autoplay" value="1" @checked($bool('success_stories_autoplay')) class="rounded border-gray-300 text-indigo-600">
                    Autoplay slides
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Slides per view — mobile</label>
                <input type="number" min="1" max="2" name="success_stories_slides_mobile" value="{{ old('success_stories_slides_mobile', $settings['success_stories_slides_mobile'] ?? 1) }}" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Slides per view — tablet</label>
                <input type="number" min="1" max="3" name="success_stories_slides_tablet" value="{{ old('success_stories_slides_tablet', $settings['success_stories_slides_tablet'] ?? 2) }}" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Slides per view — desktop</label>
                <input type="number" min="1" max="4" name="success_stories_slides_desktop" value="{{ old('success_stories_slides_desktop', $settings['success_stories_slides_desktop'] ?? 3) }}" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
        </div>

        <div class="flex flex-wrap gap-4">
            @foreach ([
                'success_stories_show_arrows' => 'Show prev/next arrows',
                'success_stories_show_dots' => 'Show dot indicators',
                'success_stories_pause_on_hover' => 'Pause autoplay on hover',
                'success_stories_loop' => 'Loop infinitely',
            ] as $field => $label)
                <input type="hidden" name="{{ $field }}" value="0">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="{{ $field }}" value="1" @checked($bool($field)) class="rounded border-gray-300 text-indigo-600">
                    {{ $label }}
                </label>
            @endforeach
        </div>

        <div class="flex justify-end">
            <button type="submit" class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                Save display settings
            </button>
        </div>
    </form>
</div>
