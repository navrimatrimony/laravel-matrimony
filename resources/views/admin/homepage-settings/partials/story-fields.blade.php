@php
    $prefix = $story ? 'story_'.$story->id.'_' : 'new_';
@endphp

<div>
    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="{{ $prefix }}couple_names">Couple names</label>
    <input id="{{ $prefix }}couple_names" type="text" name="couple_names" value="{{ old('couple_names', $story?->couple_names ?? '') }}" required class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
</div>

<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    <div>
        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="{{ $prefix }}location">Location</label>
        <input id="{{ $prefix }}location" type="text" name="location" value="{{ old('location', $story?->location ?? '') }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="{{ $prefix }}wedding_date">Wedding date</label>
        <input id="{{ $prefix }}wedding_date" type="date" name="wedding_date" value="{{ old('wedding_date', optional($story?->wedding_date)->format('Y-m-d')) }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
    </div>
</div>

<div>
    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="{{ $prefix }}story_mr">Story Marathi</label>
    <textarea id="{{ $prefix }}story_mr" name="story_mr" rows="3" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('story_mr', $story?->story_mr ?? '') }}</textarea>
</div>

<div>
    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="{{ $prefix }}story_en">Story English</label>
    <textarea id="{{ $prefix }}story_en" name="story_en" rows="3" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('story_en', $story?->story_en ?? '') }}</textarea>
</div>

<div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_7rem]">
    <div>
        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="{{ $prefix }}image">Photo</label>
        <input id="{{ $prefix }}image" type="file" name="image" accept="image/*" class="block w-full text-xs text-gray-700 dark:text-gray-200">
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300" for="{{ $prefix }}sort_order">Order</label>
        <input id="{{ $prefix }}sort_order" type="number" min="0" max="999" name="sort_order" value="{{ old('sort_order', $story?->sort_order ?? 0) }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
    </div>
</div>

<div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
    <label class="inline-flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $story?->is_published ?? false)) class="rounded border-gray-300 text-indigo-600">
        Published
    </label>
    <label class="inline-flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $story?->is_featured ?? false)) class="rounded border-gray-300 text-indigo-600">
        Featured
    </label>
    <label class="inline-flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">
        <input type="checkbox" name="consent_confirmed" value="1" @checked(old('consent_confirmed', $story?->consent_confirmed ?? false)) class="rounded border-gray-300 text-indigo-600">
        Consent
    </label>
</div>
