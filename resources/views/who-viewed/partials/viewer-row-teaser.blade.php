@php
    $photoUrl = $teaser['photo_url'] ?? null;
    $avatarStyle = $teaser['avatar_style'] ?? 'blur';
@endphp
<div class="flex items-start gap-4 border border-indigo-200/80 dark:border-indigo-800/80 rounded-lg p-3 bg-indigo-50/50 dark:bg-indigo-950/20">
    <div class="w-16 h-16 shrink-0 rounded-full overflow-hidden border border-indigo-200 dark:border-indigo-700 bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
        @if ($avatarStyle === 'blur' && $photoUrl)
            <img src="{{ $photoUrl }}" alt="" class="w-full h-full object-cover blur-md scale-110 opacity-90" loading="lazy">
        @else
            <svg class="h-8 w-8 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
        @endif
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $teaser['headline'] ?? '' }}</p>
        @foreach ($teaser['lines'] ?? [] as $line)
            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $line }}</p>
        @endforeach
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $teaser['viewed_summary'] ?? '' }}</p>
        <a href="{{ $plansUrl }}" class="mt-2 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
            {{ __('who_viewed.teaser_unlock_cta') }}
        </a>
    </div>
</div>
