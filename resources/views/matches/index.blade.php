@extends('layouts.app')

@section('content')
@php
    use App\Services\Matching\MatchingService;
    $tab = $activeTab ?? MatchingService::TAB_PERFECT;
    $tabs = [
        ['id' => MatchingService::TAB_PERFECT, 'icon' => '✦', 'label' => __('matching.tab_perfect')],
        ['id' => MatchingService::TAB_DAILY, 'icon' => '⚡', 'label' => __('matching.tab_daily')],
        ['id' => MatchingService::TAB_NEAR, 'icon' => '📍', 'label' => __('matching.tab_near')],
        ['id' => MatchingService::TAB_FRESH, 'icon' => '✨', 'label' => __('matching.tab_fresh')],
        ['id' => MatchingService::TAB_VIEWED, 'icon' => '👀', 'label' => __('matching.tab_viewed')],
        ['id' => MatchingService::TAB_INTERESTED, 'icon' => '💌', 'label' => __('matching.tab_interested')],
        ['id' => MatchingService::TAB_SECOND_CHANCE, 'icon' => '↻', 'label' => __('matching.tab_second')],
        ['id' => MatchingService::TAB_CURATED, 'icon' => '◇', 'label' => __('matching.tab_curated')],
    ];
@endphp
<div class="min-h-[70vh] bg-gradient-to-b from-rose-50/80 via-white to-violet-50/60 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950 py-6 sm:py-10">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <header class="mb-6 sm:mb-8">
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-gray-900 dark:text-gray-50">
                {{ __('matching.title') }}
            </h1>
            <p class="mt-2 text-sm sm:text-base text-gray-600 dark:text-gray-400 max-w-2xl leading-relaxed">
                {{ __('matching.subtitle') }}
            </p>
            @isset($subjectProfile)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                    {{ __('matching.profile_label') }} #{{ $subjectProfile->id }}
                    @if ($subjectProfile->full_name)
                        — {{ $subjectProfile->full_name }}
                    @endif
                </p>
            @endisset
        </header>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/40 dark:text-emerald-100" role="status">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/40 dark:bg-red-950/40 dark:text-red-100">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6 lg:gap-8 lg:items-start">
            {{-- Left: tab rail (desktop sticky; mobile full-width first) --}}
            <aside class="lg:w-56 xl:w-60 shrink-0" aria-label="{{ __('matching.title') }}">
                <nav class="lg:sticky lg:top-24 rounded-2xl border border-gray-200/90 bg-white/90 shadow-sm shadow-gray-200/40 backdrop-blur-sm dark:border-gray-700 dark:bg-gray-900/90 dark:shadow-none overflow-hidden">
                    <p class="px-4 pt-3 pb-2 text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">
                        {{ __('matching.lenses_label') }}
                    </p>
                    <ul class="py-1 max-h-[55vh] lg:max-h-[calc(100vh-12rem)] overflow-y-auto overscroll-contain">
                        @foreach ($tabs as $t)
                            @php
                                $isActive = $tab === $t['id'];
                                $tabRouteParams = ['tab' => $t['id']];
                                if (request()->routeIs('matches.show') && isset($subjectProfile)) {
                                    $tabRouteParams['matrimony_profile_id'] = $subjectProfile->id;
                                }
                                $href = request()->routeIs('matches.show') && isset($subjectProfile)
                                    ? route('matches.show', $tabRouteParams)
                                    : route('matches.index', $tabRouteParams);
                            @endphp
                            <li>
                                <a
                                    href="{{ $href }}"
                                    class="flex items-center gap-2.5 px-3 py-2.5 mx-1.5 mb-0.5 rounded-xl text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-1 dark:focus-visible:ring-offset-gray-900
                                        {{ $isActive
                                            ? 'bg-gradient-to-r from-rose-600 to-violet-600 text-white shadow-md shadow-rose-500/20'
                                            : 'text-gray-700 hover:bg-rose-50/90 dark:text-gray-200 dark:hover:bg-gray-800' }}"
                                    @if ($isActive) aria-current="page" @endif
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-base {{ $isActive ? 'bg-white/20' : 'bg-gray-100 dark:bg-gray-800' }}" aria-hidden="true">{{ $t['icon'] }}</span>
                                    <span class="leading-snug">{{ $t['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>

            {{-- Main column --}}
            <div class="min-w-0 flex-1 space-y-5">
                <div class="rounded-2xl border border-gray-200/80 bg-white/60 px-4 py-3 dark:border-gray-700 dark:bg-gray-900/40">
                    <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                        {{ __('matching.tab_hint_' . ($tab === MatchingService::TAB_SECOND_CHANCE ? 'second' : $tab)) }}
                    </p>
                    @if ($tab === MatchingService::TAB_INTERESTED)
                        <p class="mt-2">
                            <a href="{{ route('interests.index', ['tab' => 'received']) }}" class="text-sm font-semibold text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300">
                                {{ __('nav.interests_received') }} →
                            </a>
                        </p>
                    @endif
                </div>

                @if ($matches->isEmpty())
                    <div class="rounded-2xl border border-dashed border-gray-300 bg-white/70 px-6 py-14 text-center dark:border-gray-600 dark:bg-gray-800/60">
                        <p class="text-gray-600 dark:text-gray-300 text-sm sm:text-base font-medium">
                            {{ $tab === MatchingService::TAB_PERFECT ? __('matching.empty') : __('matching.empty_tab') }}
                        </p>
                    </div>
                @else
                    <ul class="space-y-4 sm:space-y-5">
                        @foreach ($matches as $row)
                            @php
                                /** @var \App\Models\MatrimonyProfile $p */
                                $p = $row['profile'];
                                $score = (int) $row['score'];
                                $baseScore = (int) ($row['base_score'] ?? $score);
                                $reasons = $row['reasons'] ?? [];
                                $boost = max(0, $score - $baseScore);
                                $nm = trim((string) ($p->full_name ?? ''));
                                $initial = $nm !== '' ? mb_strtoupper(mb_substr($nm, 0, 1, 'UTF-8'), 'UTF-8') : '?';
                            @endphp
                            <li class="group rounded-2xl border border-gray-200/90 bg-white/95 shadow-sm shadow-gray-200/40 overflow-hidden transition hover:shadow-lg hover:border-rose-200/70 dark:border-gray-700 dark:bg-gray-800/95 dark:shadow-none dark:hover:border-rose-900/50">
                                <div class="p-4 sm:p-5 flex flex-col sm:flex-row gap-4 sm:gap-5">
                                    <div class="flex-shrink-0 flex sm:block items-start gap-4">
                                        <div class="relative h-24 w-24 sm:h-28 sm:w-28 rounded-2xl overflow-hidden ring-2 ring-white shadow-md dark:ring-gray-700 bg-gradient-to-br from-rose-100 via-white to-violet-100 dark:from-gray-700 dark:via-gray-800 dark:to-gray-700">
                                            <span class="absolute inset-0 flex items-center justify-center text-2xl sm:text-3xl font-bold text-rose-700/85 dark:text-rose-300/90 select-none z-0" aria-hidden="true">{{ $initial }}</span>
                                            <img
                                                src="{{ $p->profile_photo_url }}"
                                                alt=""
                                                class="relative z-10 h-full w-full object-cover bg-white dark:bg-gray-900"
                                                loading="lazy"
                                                onerror="this.style.display='none'"
                                            >
                                        </div>
                                        <div class="sm:hidden flex-1 min-w-0 pt-0.5">
                                            <span class="inline-flex items-center rounded-lg bg-gradient-to-r from-rose-600 to-violet-600 px-2.5 py-1 text-xs font-bold text-white tabular-nums">
                                                {{ __('matching.score_percent', ['n' => $score]) }}
                                            </span>
                                            @if ($boost > 0)
                                                <p class="mt-1.5 text-[11px] font-medium text-violet-600 dark:text-violet-300">
                                                    {{ __('matching.boost_note', ['n' => $boost]) }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="min-w-0 flex-1 flex flex-col">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <h2 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-gray-50 truncate pr-2">
                                                    {{ $p->full_name ?: __('matching.profile_label') . ' #' . $p->id }}
                                                </h2>
                                                <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs sm:text-sm text-gray-600 dark:text-gray-400">
                                                    @if ($p->gender)
                                                        <div><span class="text-gray-400 dark:text-gray-500">{{ __('Gender') }}:</span> {{ $p->gender->label ?? '' }}</div>
                                                    @endif
                                                    @php($matchesLocLine = \App\Support\ProfileDisplayCopy::profileResidenceDisplayLine($p))
                                                    @if ($matchesLocLine !== '')
                                                        <div class="truncate max-w-[16rem] sm:max-w-none">
                                                            <span class="text-gray-400 dark:text-gray-500">{{ __('Location') }}:</span>
                                                            {{ $matchesLocLine }}
                                                        </div>
                                                    @endif
                                                </dl>
                                            </div>
                                            <div class="hidden sm:flex flex-col items-end gap-1 shrink-0">
                                                <span class="inline-flex items-center rounded-xl bg-gradient-to-r from-rose-600 to-violet-600 px-3 py-1.5 text-sm font-bold text-white tabular-nums shadow-sm">
                                                    {{ __('matching.score_percent', ['n' => $score]) }}
                                                </span>
                                                @if ($boost > 0)
                                                    <span class="text-[11px] font-medium text-violet-600 dark:text-violet-300 text-right max-w-[11rem] leading-snug">
                                                        {{ __('matching.boost_note', ['n' => $boost]) }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        @if (is_array($reasons) && count($reasons) > 0)
                                            <div class="mt-3 sm:mt-4">
                                                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">{{ __('matching.reasons_heading') }}</p>
                                                <ul class="flex flex-wrap gap-1.5 sm:gap-2">
                                                    @foreach ($reasons as $ri => $reason)
                                                        @php $tone = $ri % 2 === 0; @endphp
                                                        <li class="text-xs px-2.5 py-1 rounded-lg border leading-snug {{ $tone ? 'bg-emerald-50 text-emerald-900 border-emerald-100/80 dark:bg-emerald-950/40 dark:text-emerald-100 dark:border-emerald-900/40' : 'bg-violet-50 text-violet-900 border-violet-100/80 dark:bg-violet-950/40 dark:text-violet-100 dark:border-violet-900/40' }}">{{ $reason }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        <div class="mt-auto pt-4 flex flex-wrap items-center gap-2 sm:gap-3">
                                            <a href="{{ route('matrimony.profile.show', ['matrimony_profile_id' => $p->id]) }}" class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-rose-600 dark:hover:bg-rose-500 shadow-sm">
                                                {{ __('matching.view_profile') }}
                                            </a>
                                            @if (request()->routeIs('matches.index'))
                                                <form method="POST" action="{{ route('matches.skip') }}" class="inline" onsubmit="return confirm(@json(__('matching.skip_confirm')));">
                                                    @csrf
                                                    <input type="hidden" name="tab" value="{{ $tab }}">
                                                    <input type="hidden" name="candidate_profile_id" value="{{ $p->id }}">
                                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-gray-200 bg-white/90 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 hover:border-gray-300 dark:border-gray-600 dark:bg-gray-800/80 dark:text-gray-300 dark:hover:bg-gray-700/80">
                                                        {{ __('matching.skip') }}
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
