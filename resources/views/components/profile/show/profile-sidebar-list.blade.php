@props([
    'title' => '',
    'profiles' => [],
])

@if (!empty($profiles) && count($profiles) > 0)
    <div class="rounded-2xl bg-white/95 p-4 shadow-[0_8px_24px_-8px_rgba(28,25,23,0.09)] ring-1 ring-stone-200/50 dark:bg-gray-900/80 dark:shadow-[0_8px_20px_-8px_rgba(0,0,0,0.3)] dark:ring-gray-700/65 sm:p-4">
        <h3 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ $title }}</h3>
        <ul class="space-y-0.5">
            @foreach ($profiles as $p)
                @php
                    $peer = $p instanceof \App\Models\MatrimonyProfile ? $p : null;
                    if (!$peer) continue;
                    $peer->loadMissing(['district', 'state']);
                    $line = \App\Services\ProfileShowReadService::compactSummaryLine($peer);
                    $thumb = \App\Services\ProfileShowReadService::photoThumbUrl($peer);
                    $age = null;
                    if ($peer->date_of_birth) {
                        try { $age = \Carbon\Carbon::parse($peer->date_of_birth)->age; } catch (\Throwable) { $age = null; }
                    }
                @endphp
                <li>
                    <a href="{{ route('matrimony.profile.show', $peer->id) }}" class="group flex items-center gap-3 rounded-2xl px-2 py-2 transition-colors hover:bg-stone-50/90 dark:hover:bg-gray-800/70">
                        <img src="{{ $thumb }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover ring-2 ring-stone-100 shadow-sm dark:ring-gray-700" width="40" height="40" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-stone-900 group-hover:text-rose-700 dark:text-stone-100 dark:group-hover:text-rose-300">{{ $peer->full_name }}</p>
                            <p class="truncate text-[11px] leading-tight text-stone-500 dark:text-stone-400">
                                @if ($age !== null){{ $age }} yrs • @endif{{ $line }}
                            </p>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
