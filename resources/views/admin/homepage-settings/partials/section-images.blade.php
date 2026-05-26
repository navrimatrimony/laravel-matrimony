<div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Homepage section images</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Upload or change images for each section on the public homepage. JPG, PNG, or GIF — max 5MB.</p>

    <div class="mt-5 space-y-4">
        @foreach ($sections as $section)
            <div class="flex flex-col gap-4 rounded-lg border border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40 sm:flex-row sm:items-start">
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $section['label'] }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Section key: <code class="rounded bg-white px-1 py-0.5 dark:bg-gray-800">{{ $section['key'] }}</code></p>
                    @if ($section['current_url'])
                        <div class="mt-3">
                            <img src="{{ $section['current_url'] }}" alt="{{ $section['label'] }}" class="max-h-28 rounded-md border border-gray-200 object-cover dark:border-gray-600" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'text-sm text-gray-400',textContent:'Image not found'}))">
                        </div>
                    @else
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No custom upload — default or placeholder is used on the homepage.</p>
                    @endif
                </div>
                <div class="flex shrink-0 flex-col gap-2 sm:w-56">
                    <form action="{{ route('admin.homepage-settings.images.store') }}" method="POST" enctype="multipart/form-data" class="space-y-2">
                        @csrf
                        <input type="hidden" name="section_key" value="{{ $section['key'] }}">
                        <input type="file" name="image" accept="image/jpeg,image/png,image/gif" required class="block w-full text-sm text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 dark:text-gray-300 dark:file:bg-gray-600 dark:file:text-gray-200">
                        <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                            Upload
                        </button>
                        @error('image')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </form>
                    @if ($section['current_path'])
                        <form action="{{ route('admin.homepage-settings.images.clear') }}" method="POST" onsubmit="return confirm('Remove this image and use default/placeholder?');">
                            @csrf
                            <input type="hidden" name="section_key" value="{{ $section['key'] }}">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-500 dark:text-gray-200 dark:hover:bg-gray-700">
                                Clear image
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
