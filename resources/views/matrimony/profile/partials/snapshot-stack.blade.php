@php
    /** @var array<int, array<string, mixed>> $profileShowSnapshot */
    $profileShowSnapshot = $profileShowSnapshot ?? [];
    $accentStrip = [
        'stone' => 'bg-stone-500',
        'rose' => 'bg-rose-500',
        'sky' => 'bg-sky-500',
        'emerald' => 'bg-emerald-500',
        'amber' => 'bg-amber-500',
        'indigo' => 'bg-indigo-500',
        'violet' => 'bg-violet-500',
        'cyan' => 'bg-cyan-500',
        'purple' => 'bg-purple-600',
    ];
@endphp

@if ($profileShowSnapshot !== [])
    <div class="space-y-5">
        @foreach ($profileShowSnapshot as $section)
            @php
                $strip = $accentStrip[$section['accent'] ?? 'stone'] ?? 'bg-stone-500';
                $icon = $section['icon'] ?? 'document';
            @endphp
            <article
                class="relative flex overflow-hidden rounded-xl border border-stone-200/80 bg-white/90 shadow-sm ring-1 ring-stone-100/70 dark:border-gray-700/80 dark:bg-gray-900/40 dark:ring-gray-800/60"
                aria-labelledby="snapshot-{{ $section['id'] }}-heading"
            >
                <div class="w-1 shrink-0 rounded-l-xl {{ $strip }}" aria-hidden="true"></div>
                <div class="min-w-0 flex-1 px-4 py-5 sm:px-5 sm:py-6">
                    <header class="mb-4 flex flex-wrap items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-stone-100 text-stone-600 shadow-inner dark:bg-stone-800 dark:text-stone-300" aria-hidden="true">
                            @switch($icon)
                                @case('user')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                    @break
                                @case('academic-cap')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 00-.491 6.347A48.62 48.62 0 0112 20.904a48.62 48.62 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.636 50.636 0 00-2.658-.813A59.906 59.906 0 0112 3.493a59.903 59.903 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
                                    @break
                                @case('home')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                                    @break
                                @case('users')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.876-2.292.018.018 0 01-.016.007 0 0-.017-.006-.018-.006M4.026 19.128a9.345 9.345 0 011.021-.004 9.37 9.37 0 002.25-.402m9.384-15.09a2.25 2.25 0 00-3.182 0l-8.25 8.25a2.25 2.25 0 000 3.182l8.25 8.25a2.25 2.25 0 003.182 0l8.25-8.25a2.25 2.25 0 000-3.182l-8.25-8.25z"/></svg>
                                    @break
                                @case('map')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                                    @break
                                @case('building')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-4.875c0-.621-.504-1.125-1.125h-4.5c-.621 0-1.125.504-1.125 1.125V21M3.375 9.75h17.25c.621 0 1.125-.504 1.125-1.125v-2.25c0-1.036-.84-1.875-1.875-1.875H4.125c-1.036 0-1.875.84-1.875 1.875v2.25c0 .621.504 1.125 1.125 1.125z"/></svg>
                                    @break
                                @case('sparkles')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                                    @break
                                @case('heart')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733C15.273 3.256 13.612 2.25 11.677 2.25c-1.935 0-3.596 1.006-4.311 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                                    @break
                                @case('id-card')
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
                                    @break
                                @default
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                            @endswitch
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-stone-400 dark:text-stone-500">{{ $section['kicker'] ?? '' }}</p>
                            <h3 id="snapshot-{{ $section['id'] }}-heading" class="text-lg font-semibold tracking-tight text-stone-900 dark:text-stone-50">
                                {{ $section['title'] ?? '' }}
                            </h3>
                        </div>
                    </header>

                    @if (! empty($section['rows']))
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach ($section['rows'] as $row)
                                <div @class([
                                    'rounded-lg border border-stone-100/95 bg-stone-50/60 px-3 py-2.5 dark:border-gray-700/60 dark:bg-gray-800/40',
                                    'sm:col-span-2' => ! empty($row['full']),
                                ])>
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ $row['label'] ?? '' }}</p>
                                    @if (! empty($row['locked']))
                                        <div class="relative mt-1.5 flex min-h-[2.75rem] items-center justify-center overflow-hidden rounded-md bg-stone-200/40 dark:bg-stone-700/30">
                                            <span class="select-none blur-sm text-lg tracking-widest text-stone-300 dark:text-stone-600" aria-hidden="true">0000</span>
                                            <span class="absolute inset-0 flex items-center justify-center bg-white/35 dark:bg-gray-900/35">
                                                <svg class="h-5 w-5 text-stone-600 dark:text-stone-300" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M12 1.5a5.25 5.25 0 00-5.25 5.25v3a3 3 0 00-3 3v6.75a3 3 0 003 3h10.5a3 3 0 003-3v-6.75a3 3 0 00-3-3v-3A5.25 5.25 0 0012 1.5zm3.75 8.25v-3a3.75 3.75 0 10-7.5 0v3h7.5z" clip-rule="evenodd" />
                                                </svg>
                                            </span>
                                        </div>
                                    @else
                                        <p @class([
                                            'mt-1 text-[15px] font-medium leading-snug text-stone-900 dark:text-stone-100',
                                            'whitespace-pre-wrap' => ! empty($row['full']),
                                        ])>{{ $row['value'] ?? '' }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($section['marriage_blocks']))
                        <div class="mt-4 space-y-4">
                            @foreach ($section['marriage_blocks'] as $blockLines)
                                <div class="rounded-lg border border-stone-100/90 bg-stone-50/50 p-3 dark:border-gray-700/60 dark:bg-gray-800/35">
                                    <ul class="space-y-2">
                                        @foreach ($blockLines as $line)
                                            <li class="flex gap-2 text-sm leading-relaxed text-stone-800 dark:text-stone-100">
                                                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-rose-400/90 dark:bg-rose-500" aria-hidden="true"></span>
                                                <span>{{ $line }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($section['timelines']))
                        <div class="mt-4 space-y-5">
                            @foreach ($section['timelines'] as $tl)
                                <div>
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ $tl['title'] ?? '' }}</p>
                                    <ol class="relative space-y-2 border-l-2 border-stone-200/90 pl-4 dark:border-stone-600/80">
                                        @foreach ($tl['items'] ?? [] as $item)
                                            <li class="relative text-sm font-medium text-stone-800 dark:text-stone-100">
                                                <span class="absolute -left-[21px] top-1.5 flex h-2 w-2 rounded-full border-2 border-white bg-stone-400 dark:border-gray-900 dark:bg-stone-500" aria-hidden="true"></span>
                                                {{ $item }}
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($section['groups']))
                        <div class="mt-2 space-y-5">
                            @foreach ($section['groups'] as $group)
                                <div>
                                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ $group['heading'] ?? '' }}</p>
                                    <div class="space-y-2">
                                        @foreach ($group['lines'] ?? [] as $line)
                                            <div class="rounded-lg border border-stone-100/95 bg-stone-50/50 px-3 py-2.5 text-sm font-medium text-stone-900 dark:border-gray-700/60 dark:bg-gray-800/40 dark:text-stone-100">
                                                {{ $line }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
@endif
