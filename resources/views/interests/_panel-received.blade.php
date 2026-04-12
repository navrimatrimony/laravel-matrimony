@php
    $periodLabel = match ($interestViewPeriod ?? 'monthly') {
        'weekly' => __('interests.period_weekly'),
        'quarterly' => __('interests.period_quarterly'),
        default => __('interests.period_monthly'),
    };
    $limitVal = $interestViewLimit ?? -1;
@endphp
<div class="mb-6">
    <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ __('interests.received_interests') }}</h2>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('interests.received_subtitle') }}</p>
</div>

@if (($interestViewLimit ?? -1) >= 0)
    <div class="mb-8 rounded-2xl border border-amber-200/90 bg-gradient-to-br from-amber-50 via-white to-rose-50/80 p-5 shadow-sm dark:border-amber-900/40 dark:from-amber-950/30 dark:via-gray-900 dark:to-rose-950/20">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">{{ __('interests.interest_view_policy_title') }}</p>
                <p class="mt-2 text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                    @if ($limitVal === 0)
                        {{ __('interests.interest_view_zero') }}
                    @else
                        {{ __('interests.interest_view_banner', ['count' => $limitVal, 'period' => $periodLabel]) }}
                    @endif
                </p>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                    {{ __('interests.interest_view_window', ['start' => $interestViewWindowStart->timezone(config('app.timezone'))->format('M j, Y')]) }}
                </p>
            </div>
            <a href="{{ route('plans.index') }}"
               class="inline-flex shrink-0 items-center justify-center rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-rose-600/20 transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                {{ __('interests.upgrade_for_more_reveals') }}
            </a>
        </div>
    </div>
@else
    <div class="mb-8 rounded-2xl border border-emerald-200/80 bg-emerald-50/60 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-100">
        {{ __('interests.interest_view_unlimited_plan') }}
    </div>
@endif

@forelse ($receivedInterests as $interest)
    @php
        $unlocked = ($unlockById[$interest->id] ?? true) === true;
        $sender = $interest->senderProfile;
    @endphp
    <article class="mb-5 overflow-hidden rounded-2xl border border-gray-200/90 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800/90">
        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-stretch sm:gap-6">
            <div class="relative shrink-0">
                @if ($unlocked && $sender && $sender->profile_photo && $sender->photo_approved !== false)
                    <img src="{{ app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($sender->profile_photo) }}"
                         alt=""
                         class="h-24 w-24 rounded-2xl object-cover ring-2 ring-white dark:ring-gray-700">
                @elseif ($unlocked && $sender)
                    @php
                        $gk = $sender->gender?->key ?? null;
                        $ph = $gk === 'male' ? asset('images/placeholders/male-profile.svg') : ($gk === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
                    @endphp
                    <img src="{{ $ph }}" alt="" class="h-24 w-24 rounded-2xl object-cover ring-2 ring-white opacity-95 dark:ring-gray-700">
                @else
                    <div class="relative h-24 w-24 overflow-hidden rounded-2xl bg-gradient-to-br from-gray-300 to-gray-500 dark:from-gray-600 dark:to-gray-800">
                        <div class="absolute inset-0 backdrop-blur-md bg-white/10"></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <svg class="h-10 w-10 text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                    </div>
                @endif
                @if (! $unlocked)
                    <span class="absolute -bottom-1 -right-1 flex h-8 w-8 items-center justify-center rounded-full bg-amber-500 text-white shadow-lg ring-2 ring-white dark:ring-gray-800" title="{{ __('interests.locked_badge') }}">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                    </span>
                @endif
            </div>

            <div class="min-w-0 flex-1">
                @if ($unlocked)
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('interests.from') }}: {{ $sender?->full_name ?? __('interests.profile_deleted') }}
                    </p>
                    @if ($sender)
                        <p class="mt-2">
                            <a href="{{ route('matrimony.profile.show', $sender->id) }}"
                               class="inline-flex items-center gap-1 text-sm font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400 dark:hover:text-rose-300">
                                {{ __('interests.view_matrimony_profile') }}
                                <span aria-hidden="true">→</span>
                            </a>
                        </p>
                    @endif
                @else
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('interests.from_locked_title') }}
                    </p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('interests.from_locked_body') }}
                    </p>
                    <p class="mt-3">
                        <a href="{{ route('plans.index') }}" class="inline-flex text-sm font-semibold text-rose-600 hover:text-rose-800 dark:text-rose-400">
                            {{ __('interests.unlock_with_membership') }} →
                        </a>
                    </p>
                @endif

                <p class="mt-3 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('interests.status') }}:</span>
                    @if ($interest->status === 'pending')
                        <span class="font-medium text-amber-700 dark:text-amber-300">{{ __('interests.pending') }}</span>
                    @elseif ($interest->status === 'accepted')
                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ __('interests.accepted') }}</span>
                    @elseif ($interest->status === 'rejected')
                        <span class="font-medium text-red-600 dark:text-red-400">{{ __('interests.rejected') }}</span>
                    @endif
                </p>

                @if ($interest->status === 'pending')
                    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                        <form method="POST" action="{{ route('interests.accept', $interest) }}" class="w-full sm:w-auto">
                            @csrf
                            <button type="submit" class="inline-flex w-full min-h-[2.75rem] items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 sm:min-w-[9rem]">
                                {{ __('interests.accept') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('interests.reject', $interest) }}" class="w-full sm:w-auto">
                            @csrf
                            <button type="submit" class="inline-flex w-full min-h-[2.75rem] items-center justify-center rounded-xl border-2 border-red-200 bg-white px-5 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-50 dark:border-red-900/50 dark:bg-gray-800 dark:text-red-300 dark:hover:bg-red-950/30 sm:min-w-[9rem]">
                                {{ __('interests.reject') }}
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </article>
@empty
    <p class="text-center text-gray-600 dark:text-gray-400">
        {{ ($statusFilter ?? 'all') !== 'all' ? __('interests.no_received_for_filter') : __('interests.no_received_interests') }}
    </p>
@endforelse
