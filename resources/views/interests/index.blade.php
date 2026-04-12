@extends('layouts.app')

@section('content')
@php
    $isReceived = ($activeTab ?? 'received') === 'received';
    $statusFilter = $statusFilter ?? 'all';
    $counts = $isReceived ? ($receivedCounts ?? ['all' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0]) : ($sentCounts ?? ['all' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0]);
    $hubParams = static function (string $tab, string $status): array {
        $p = [];
        if ($tab === 'sent') {
            $p['tab'] = 'sent';
        }
        if ($status !== 'all') {
            $p['status'] = $status;
        }

        return $p;
    };
    $statusKeys = ['all', 'pending', 'accepted', 'rejected'];
@endphp
<div class="overflow-x-hidden py-10">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <header class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                {{ __('interests.page_heading') }}
            </h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('interests.page_subheading') }}</p>
        </header>
    </div>

    {{-- Full-width colored band: Inbox + Received / Sent --}}
    <section
        class="relative left-1/2 z-0 mb-8 w-screen max-w-[100vw] -translate-x-1/2 border-y border-rose-900/10 bg-gradient-to-r from-rose-600 via-rose-500 to-violet-600 py-6 shadow-md dark:border-rose-950/40 dark:from-rose-950 dark:via-rose-900 dark:to-violet-950"
        aria-labelledby="interests-inbox-heading"
    >
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <h2 id="interests-inbox-heading" class="mb-3 text-[11px] font-bold uppercase tracking-[0.2em] text-white/90">
                {{ __('interests.group_inbox') }}
            </h2>
            <nav
                class="w-full max-w-2xl rounded-2xl border border-white/20 bg-black/10 p-1.5 shadow-inner backdrop-blur-sm dark:border-white/10 dark:bg-black/25"
                aria-label="{{ __('interests.group_inbox') }}"
            >
                <div class="grid w-full grid-cols-2 gap-1.5">
                    <a
                        href="{{ route('interests.index', $hubParams('received', $statusFilter)) }}"
                        class="flex min-h-[2.85rem] items-center justify-center rounded-xl px-3 py-2.5 text-center text-sm font-bold transition-all duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-rose-600
                            {{ $isReceived
                                ? 'bg-white text-rose-700 shadow-lg shadow-rose-900/25 ring-1 ring-white/80 dark:bg-gray-100 dark:text-rose-800'
                                : 'text-white/95 hover:bg-white/15 hover:text-white dark:text-white/90' }}"
                        @if ($isReceived) aria-current="page" @endif
                    >
                        {{ __('interests.tab_received') }}
                    </a>
                    <a
                        href="{{ route('interests.index', $hubParams('sent', $statusFilter)) }}"
                        class="flex min-h-[2.85rem] items-center justify-center rounded-xl px-3 py-2.5 text-center text-sm font-bold transition-all duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-rose-600
                            {{ ! $isReceived
                                ? 'bg-white text-rose-700 shadow-lg shadow-rose-900/25 ring-1 ring-white/80 dark:bg-gray-100 dark:text-rose-800'
                                : 'text-white/95 hover:bg-white/15 hover:text-white dark:text-white/90' }}"
                        @if (! $isReceived) aria-current="page" @endif
                    >
                        {{ __('interests.tab_sent') }}
                    </a>
                </div>
            </nav>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- Status: All | Pending | Accepted | Rejected --}}
        <div class="mb-8 w-full">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('interests.group_status') }}</p>
            <nav
                class="-mx-1 overflow-x-auto pb-1 px-1 sm:mx-0 sm:overflow-visible sm:px-0"
                aria-label="{{ __('interests.group_status') }}"
            >
                <div class="inline-flex min-w-full gap-1 rounded-2xl border border-gray-200/80 bg-slate-100/90 p-1 sm:inline-grid sm:min-w-0 sm:w-full sm:max-w-2xl sm:grid-cols-4 dark:border-gray-700 dark:bg-gray-900/80">
                    @foreach ($statusKeys as $key)
                        @php
                            $isActive = $statusFilter === $key;
                            $n = (int) ($counts[$key] ?? 0);
                        @endphp
                        <a
                            href="{{ route('interests.index', $hubParams($isReceived ? 'received' : 'sent', $key)) }}"
                            class="flex min-h-[2.5rem] shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl px-3 py-2 text-center text-xs font-semibold transition-all duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 sm:text-sm
                                {{ $isActive
                                    ? 'bg-white text-rose-700 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-800 dark:text-rose-300 dark:ring-gray-600'
                                    : 'text-gray-600 hover:bg-white/60 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800/70 dark:hover:text-gray-100' }}"
                            @if ($isActive) aria-current="page" @endif
                        >
                            <span>{{ __('interests.filter_'.$key) }}</span>
                            @if ($n > 0)
                                <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-rose-800 dark:bg-rose-950/80 dark:text-rose-200 sm:text-xs">{{ $n }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </nav>
        </div>

        @if ($isReceived)
            @include('interests._panel-received')
        @else
            @include('interests._panel-sent')
        @endif
    </div>
</div>
@endsection
