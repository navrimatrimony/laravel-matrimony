@php
    /** @var \App\Models\HomepageSuccessStory $story */
    $isMarathiLocale = str_starts_with((string) app()->getLocale(), 'mr');
    $devanagariClass = $isMarathiLocale ? 'font-devanagari' : '';
    $storyText = $isMarathiLocale
        ? ($story->story_mr ?: $story->story_en)
        : ($story->story_en ?: $story->story_mr);
@endphp
<article class="nmn-success-story-card overflow-hidden rounded-lg border border-red-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    @if ($story->imageUrl())
        <img src="{{ $story->imageUrl() }}" alt="" class="h-48 w-full object-cover">
    @else
        <div class="flex h-48 w-full items-center justify-center bg-red-50 text-sm font-semibold text-[var(--brand-red)] dark:bg-red-950/30">♥</div>
    @endif
    <div class="p-5">
        <div class="flex items-start justify-between gap-3">
            <h3 class="{{ $devanagariClass }} text-lg font-extrabold text-zinc-950 dark:text-white">{{ $story->couple_names }}</h3>
            @if ($story->is_featured)
                <span class="shrink-0 rounded-full bg-amber-100 px-2 py-1 text-[11px] font-bold text-amber-800">{{ __('homepage.featured') }}</span>
            @endif
        </div>
        @if ($story->location || $story->wedding_date)
            <p class="mt-1 text-xs font-semibold text-zinc-500">
                {{ $story->location }}
                @if ($story->wedding_date)
                    @if ($story->location) · @endif
                    {{ $story->wedding_date->format('M Y') }}
                @endif
            </p>
        @endif
        @if ($storyText)
            <p class="{{ $devanagariClass }} mt-4 text-sm leading-7 text-zinc-700 dark:text-zinc-300">{{ $storyText }}</p>
        @endif
    </div>
</article>
