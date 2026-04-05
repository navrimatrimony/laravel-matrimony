@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-4xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
        {{ $isEdit ? __('subscriptions.edit_plan') : __('subscriptions.create_plan') }}
    </h1>

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ $isEdit ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="space-y-6">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                <input type="text" name="name" value="{{ old('name', $plan->name) }}" required maxlength="120"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug</label>
                <input type="text" name="slug" value="{{ old('slug', $plan->slug) }}" required maxlength="64" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Price (₹)</label>
                <input type="number" name="price" value="{{ old('price', $plan->price ?? '') }}" required min="0" step="0.01"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Discount %</label>
                <input type="number" name="discount_percent" value="{{ old('discount_percent', $plan->discount_percent ?? '') }}" min="0" max="100" step="1"
                    placeholder="None"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Duration (days, 0 = no expiry)</label>
                <input type="number" name="duration_days" value="{{ old('duration_days', $plan->duration_days) }}" required min="0"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sort order</label>
                <input type="number" name="sort_order" value="{{ old('sort_order', $plan->sort_order) }}" min="0"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm" />
            </div>
        </div>

        <div class="flex flex-wrap gap-6">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="is_active" value="0" />
                <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300" @checked((string) old('is_active', $plan->is_active ? '1' : '0') === '1') />
                <span class="text-sm text-gray-700 dark:text-gray-300">Active (visible for new subscriptions)</span>
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="highlight" value="0" />
                <input type="checkbox" name="highlight" value="1" class="rounded border-gray-300" @checked((string) old('highlight', $plan->highlight ? '1' : '0') === '1') />
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('subscriptions.highlight_plan') }}</span>
            </label>
        </div>

        @if ($isEdit && strtolower((string) $plan->slug) !== 'free')
            <div class="rounded-lg border border-indigo-200 dark:border-indigo-900 bg-indigo-50/50 dark:bg-indigo-950/20 p-4 space-y-4">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('subscriptions.admin_billing_periods_title') }}</h2>
                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('subscriptions.admin_billing_periods_intro') }}</p>
                <div class="space-y-4">
                    @foreach (\App\Models\PlanTerm::billingKeys() as $billingKey)
                        @php
                            $termRow = $plan->terms->firstWhere('billing_key', $billingKey);
                        @endphp
                        <div class="rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-3 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                            <div class="sm:col-span-3">
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('subscriptions.billing_'.$billingKey) }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-500">{{ __('subscriptions.duration_days', ['count' => \App\Models\PlanTerm::durationDaysFor($billingKey)]) }}</span>
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Price (₹)</label>
                                <input type="number" name="terms[{{ $billingKey }}][price]" min="0" step="0.01" required
                                    value="{{ old('terms.'.$billingKey.'.price', $termRow?->price ?? '0') }}"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Discount %</label>
                                <input type="number" name="terms[{{ $billingKey }}][discount_percent]" min="0" max="100" step="1"
                                    value="{{ old('terms.'.$billingKey.'.discount_percent', $termRow?->discount_percent ?? '') }}"
                                    placeholder="—"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                            </div>
                            <div class="sm:col-span-3 flex items-center">
                                <input type="hidden" name="terms[{{ $billingKey }}][is_visible]" value="0" />
                                <label class="inline-flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" name="terms[{{ $billingKey }}][is_visible]" value="1" class="rounded border-gray-300"
                                        @checked((string) old('terms.'.$billingKey.'.is_visible', $termRow?->is_visible ? '1' : '0') === '1') />
                                    <span>{{ __('subscriptions.admin_billing_show_public') }}</span>
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif (! $isEdit)
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('subscriptions.admin_billing_after_create') }}</p>
        @endif

        <div>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Feature limits</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('subscriptions.feature_key_hint') }}</p>
            <div class="space-y-2">
                @foreach ($defaultFeatures as $idx => $row)
                    <div class="flex flex-wrap gap-2 items-center">
                        <input type="text" name="features[{{ $idx }}][key]" value="{{ old('features.'.$idx.'.key', $row['key']) }}" placeholder="key"
                            class="flex-1 min-w-[12rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        <input type="text" name="features[{{ $idx }}][value]" value="{{ old('features.'.$idx.'.value', $row['value']) }}" placeholder="value"
                            class="flex-1 min-w-[8rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                Save
            </button>
            <a href="{{ route('admin.plans.index') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
