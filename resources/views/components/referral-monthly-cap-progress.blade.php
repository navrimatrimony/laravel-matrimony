@props(['progress'])

@php
    $progress = is_array($progress ?? null) ? $progress : null;
@endphp

@if ($progress !== null && (int) ($progress['cap'] ?? 0) > 0)
    @php
        $earned = (int) ($progress['earned'] ?? 0);
        $cap = (int) ($progress['cap'] ?? 0);
        $remaining = (int) ($progress['remaining'] ?? 0);
        $atCap = (bool) ($progress['at_cap'] ?? false);
    @endphp
    <div {{ $attributes->merge(['class' => 'rounded-lg border px-3 py-2 text-sm']) }}
         @class([
             'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100' => $atCap,
             'border-gray-200/90 bg-gray-50/90 text-gray-700 dark:border-gray-600 dark:bg-gray-900/50 dark:text-gray-300' => ! $atCap,
         ])
         role="status">
        <p class="font-semibold tabular-nums">{{ __('referrals.monthly_cap_progress', ['earned' => $earned, 'cap' => $cap]) }}</p>
        @if ($atCap)
            <p class="mt-1 text-xs opacity-90">{{ __('referrals.monthly_cap_full') }}</p>
        @elseif ($remaining > 0)
            <p class="mt-1 text-xs opacity-90">{{ trans_choice('referrals.monthly_cap_remaining', $remaining, ['remaining' => $remaining]) }}</p>
        @endif
    </div>
@endif
