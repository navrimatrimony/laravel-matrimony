@props([
    'profileViewersCount' => 0,
    'unreadMessages' => 0,
    'planExpiresInDays' => null,
    'walletBalanceDisplay' => null,
    'shareReferralUrl' => null,
])

@php
    $show = ($profileViewersCount > 0 && $profileViewersCount <= 20)
        || ($unreadMessages > 0 && $unreadMessages <= 20)
        || ($planExpiresInDays !== null && $planExpiresInDays <= 7);
@endphp

@if ($show)
    <div {{ $attributes->merge(['class' => 'mb-6 rounded-xl border border-amber-200/90 bg-amber-50/95 p-4 text-amber-950 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-100']) }} role="status">
        <p class="text-sm font-semibold text-amber-900 dark:text-amber-50">{{ __('dashboard.monetization_activity_title') }}</p>
        <ul class="mt-2 space-y-1 text-sm text-amber-900/90 dark:text-amber-100/90 list-disc list-inside">
            @if ($profileViewersCount > 0)
                <li>{{ trans_choice('dashboard.monetization_viewed_you', $profileViewersCount, ['count' => $profileViewersCount]) }}</li>
            @endif
            @if ($unreadMessages > 0)
                <li>{{ trans_choice('dashboard.monetization_messages_waiting', $unreadMessages, ['count' => $unreadMessages]) }}</li>
            @endif
            @if ($planExpiresInDays !== null && $planExpiresInDays <= 7)
                <li>{{ trans_choice('dashboard.monetization_plan_expires', $planExpiresInDays, ['days' => $planExpiresInDays]) }}</li>
            @endif
        </ul>
        @if ($walletBalanceDisplay !== null)
            <p class="mt-2 text-xs text-amber-800/90 dark:text-amber-200/90">{{ __('dashboard.monetization_wallet_balance', ['amount' => $walletBalanceDisplay]) }}</p>
        @endif
        @if ($shareReferralUrl)
            <p class="mt-2 text-xs text-amber-800/90 dark:text-amber-200/90">{{ __('dashboard.monetization_referral_hint') }}
                <a href="{{ $shareReferralUrl }}" class="font-mono underline break-all">{{ $shareReferralUrl }}</a>
            </p>
        @endif
    </div>
@endif
