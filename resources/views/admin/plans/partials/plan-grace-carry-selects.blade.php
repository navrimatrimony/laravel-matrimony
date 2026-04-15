@php
    $showPlanDiscount = $showPlanDiscount ?? true;
    $graceOpts = \App\Http\Controllers\Admin\PlanController::ADMIN_GRACE_PERIOD_DAY_OPTIONS;
    $carryOpts = \App\Http\Controllers\Admin\PlanController::ADMIN_LEFTOVER_CARRY_DAY_OPTIONS;
    $graceVal = (int) old('grace_period_days', $plan->grace_period_days ?? 3);
    if (! in_array($graceVal, $graceOpts, true)) {
        $graceVal = in_array(3, $graceOpts, true) ? 3 : ($graceOpts[0] ?? 0);
    }
    $carryOld = old('leftover_quota_carry_window_days', $plan->leftover_quota_carry_window_days);
    $carryVal = ($carryOld === '' || $carryOld === null) ? null : (int) $carryOld;
    if ($carryVal !== null && ! in_array($carryVal, $carryOpts, true)) {
        $carryVal = null;
    }
@endphp
{{-- Three grid cells; parent must use `grid grid-cols-1 md:grid-cols-3` --}}
<div class="flex flex-col gap-2 min-w-0">
    <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="plan-admin-grace-days">{{ __('subscriptions.plan_grace_period_days_label') }}</label>
    <select id="plan-admin-grace-days" name="grace_period_days" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
        @foreach ($graceOpts as $gd)
            <option value="{{ $gd }}" @selected($graceVal === $gd)>{{ __('subscriptions.admin_plan_grace_days_'.$gd) }}</option>
        @endforeach
    </select>
</div>
<div class="flex flex-col gap-2 min-w-0">
    <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="plan-admin-carry-window">{{ __('subscriptions.plan_leftover_quota_carry_window_days_label') }}</label>
    <select id="plan-admin-carry-window" name="leftover_quota_carry_window_days" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20">
        <option value="" @selected($carryVal === null)>{{ __('subscriptions.admin_plan_carry_opt_none') }}</option>
        @foreach ($carryOpts as $cd)
            <option value="{{ $cd }}" @selected($carryVal === $cd)>{{ __('subscriptions.admin_plan_carry_days_'.$cd) }}</option>
        @endforeach
    </select>
</div>
@if ($showPlanDiscount)
    <div class="flex flex-col gap-2 min-w-0">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="plan-admin-plan-discount">{{ __('subscriptions.admin_plan_discount_percent_label') }}</label>
        <input id="plan-admin-plan-discount" type="number" name="discount_percent" value="{{ old('discount_percent', $plan->discount_percent ?? '') }}" min="0" max="100" step="1" placeholder="—"
            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm py-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20" />
    </div>
@else
    <div class="hidden md:block min-h-[2.75rem]" aria-hidden="true"></div>
@endif
