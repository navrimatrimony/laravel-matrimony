@props([
    'title' => '',
    'subtitle' => null,
])

<section {{ $attributes->merge(['class' => 'rounded-2xl border border-stone-200/60 bg-white/90 p-4 shadow-sm ring-1 ring-stone-100/80 dark:border-gray-700/70 dark:bg-gray-900/50 dark:ring-gray-800/80 sm:p-5']) }}>
    @if ($title !== '')
        <h2 class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-100">{{ $title }}</h2>
    @endif
    @if ($subtitle)
        <p class="mt-1.5 text-sm leading-relaxed text-stone-600 dark:text-stone-400">{{ $subtitle }}</p>
    @endif
    <div class="{{ $title !== '' || $subtitle ? 'mt-4' : '' }}">
        {{ $slot }}
    </div>
</section>
