@extends('layouts.admin')

@php
    /** @var \App\Models\Coupon $coupon */
    $selectedPlans = old('plan_ids', $coupon->applicable_plan_ids ?? []);
    $selectedDurations = old('duration_types', $coupon->applicable_duration_types ?? []);
    $typeVal = old('type', $coupon->type === \App\Models\Coupon::TYPE_FIXED ? \App\Models\Coupon::TYPE_FLAT : $coupon->type);
@endphp

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-3xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
        {{ $isEdit ? __('admin_commerce.coupon_edit') : __('admin_commerce.coupon_create') }}
    </h1>

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ $isEdit ? route('admin.commerce.coupons.update', $coupon) : route('admin.commerce.coupons.store') }}" class="space-y-5">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_code') }}</label>
            <input type="text" name="code" value="{{ old('code', $coupon->code) }}" required maxlength="64"
                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm font-mono uppercase" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_type') }}</label>
                <select name="type" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm">
                    <option value="{{ \App\Models\Coupon::TYPE_PERCENT }}" @selected($typeVal === \App\Models\Coupon::TYPE_PERCENT)>{{ __('admin_commerce.coupon_type_percent') }}</option>
                    <option value="{{ \App\Models\Coupon::TYPE_FLAT }}" @selected($typeVal === \App\Models\Coupon::TYPE_FLAT)>{{ __('admin_commerce.coupon_type_flat') }}</option>
                    <option value="{{ \App\Models\Coupon::TYPE_DAYS }}" @selected($typeVal === \App\Models\Coupon::TYPE_DAYS)>{{ __('admin_commerce.coupon_type_days') }}</option>
                    <option value="{{ \App\Models\Coupon::TYPE_FEATURE }}" @selected($typeVal === \App\Models\Coupon::TYPE_FEATURE)>{{ __('admin_commerce.coupon_type_feature') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_value') }}</label>
                <input type="number" name="value" value="{{ old('value', $coupon->value) }}" required min="0" step="0.01"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('admin_commerce.coupon_value_hint') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-4">
            <div class="sm:col-span-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin_commerce.coupon_feature_section') }}</div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_feature_key') }}</label>
                <input type="text" name="feature_key" value="{{ old('feature_key', $coupon->feature_payload['feature_key'] ?? '') }}" maxlength="64"
                    placeholder="e.g. chat_can_read"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm font-mono text-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_grant_days') }}</label>
                <input type="number" name="grant_days" value="{{ old('grant_days', $coupon->feature_payload['grant_days'] ?? 30) }}" min="1" max="3650"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_max_redemptions') }}</label>
                <input type="number" name="max_redemptions" value="{{ old('max_redemptions', $coupon->max_redemptions) }}" min="1"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_min_purchase') }}</label>
                <input type="number" name="min_purchase_amount" value="{{ old('min_purchase_amount', $coupon->min_purchase_amount) }}" min="0" step="0.01"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_valid_from') }}</label>
                <input type="datetime-local" name="valid_from" value="{{ old('valid_from', $coupon->valid_from?->format('Y-m-d\TH:i')) }}"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_valid_until') }}</label>
                <input type="datetime-local" name="valid_until" value="{{ old('valid_until', $coupon->valid_until?->format('Y-m-d\TH:i')) }}"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('admin_commerce.coupon_plans') }}</label>
            <div class="max-h-40 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-600 p-2 space-y-1">
                @foreach ($plans as $p)
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="plan_ids[]" value="{{ $p->id }}"
                            @checked(in_array((int) $p->id, array_map('intval', (array) $selectedPlans), true)) />
                        {{ $p->name }} <span class="text-gray-400 font-mono text-xs">({{ $p->slug }})</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('admin_commerce.coupon_durations') }}</label>
            <div class="flex flex-wrap gap-3">
                @foreach ($durationTypes as $dt)
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="duration_types[]" value="{{ $dt }}"
                            @checked(in_array($dt, (array) $selectedDurations, true)) />
                        {{ $dt }}
                    </label>
                @endforeach
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin_commerce.coupon_description') }}</label>
            <input type="text" name="description" value="{{ old('description', $coupon->description) }}" maxlength="255"
                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
        </div>

        <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="hidden" name="is_active" value="0" />
            <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300" @checked(old('is_active', $coupon->is_active ? '1' : '0') === '1') />
            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('admin_commerce.coupon_active') }}</span>
        </label>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Save</button>
            <a href="{{ route('admin.commerce.coupons.index') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
