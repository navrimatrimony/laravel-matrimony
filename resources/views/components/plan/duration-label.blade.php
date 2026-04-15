@props([
    'days' => 0,
])

@php
    $d = (int) $days;
    $text = match (true) {
        $d === 0 => __('subscriptions.period_label_lifetime'),
        $d === 30 => __('subscriptions.period_label_monthly'),
        $d === 90 => __('subscriptions.period_label_quarterly'),
        $d === 180 => __('subscriptions.period_label_half_yearly'),
        $d === 365 => __('subscriptions.period_label_yearly'),
        $d === 1825 => __('subscriptions.period_label_five_yearly'),
        default => __('subscriptions.period_label_days', ['count' => $d]),
    };
@endphp

<span {{ $attributes }}>{{ $text }}</span>
