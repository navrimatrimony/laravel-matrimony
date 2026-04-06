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

    @php
        $featureRowsForForm = [];
        if (is_array(old('features'))) {
            foreach (old('features') as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $featureRowsForForm[] = [
                    'key' => (string) ($r['key'] ?? ''),
                    'value' => (string) ($r['value'] ?? ''),
                ];
            }
        }
        if ($featureRowsForForm === [] && $isEdit) {
            $plan->loadMissing('features');
            foreach ($plan->features->sortBy('key')->values() as $f) {
                $featureRowsForForm[] = ['key' => $f->key, 'value' => (string) $f->value];
            }
        }
        if ($featureRowsForForm === [] && ! $isEdit) {
            foreach ($defaultFeatures as $row) {
                $featureRowsForForm[] = [
                    'key' => (string) ($row['key'] ?? ''),
                    'value' => (string) ($row['value'] ?? ''),
                ];
            }
        }
        if ($featureRowsForForm === []) {
            $featureRowsForForm[] = ['key' => '', 'value' => ''];
        }
    @endphp

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
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">{{ __('admin_commerce.features_manage_title') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('subscriptions.feature_key_hint') }}</p>
            <div id="plan-feature-rows" class="space-y-2" data-next-index="{{ count($featureRowsForForm) }}">
                @foreach ($featureRowsForForm as $idx => $row)
                    <div class="plan-feature-row flex flex-wrap gap-2 items-center rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-2" data-row>
                        <input type="text" name="features[{{ $idx }}][key]" value="{{ $row['key'] }}" placeholder="key"
                            class="flex-1 min-w-[12rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        <input type="text" name="features[{{ $idx }}][value]" value="{{ $row['value'] }}" placeholder="value"
                            class="flex-1 min-w-[8rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
                        <button type="button" class="remove-plan-feature-row text-sm text-red-600 dark:text-red-400 hover:underline shrink-0">
                            {{ __('admin_commerce.features_delete_row') }}
                        </button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-plan-feature-row" class="mt-3 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                {{ __('admin_commerce.features_add_button') }}
            </button>
        </div>

        <div class="flex gap-3 pt-2 border-t border-gray-200 dark:border-gray-600">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                {{ __('admin_commerce.plan_save_changes') }}
            </button>
            <a href="{{ route('admin.plans.index') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 text-sm">Cancel</a>
        </div>
    </form>
</div>
<script>
(function () {
    var removeLabel = @json(__('admin_commerce.features_delete_row'));
    var wrap = document.getElementById('plan-feature-rows');
    var btn = document.getElementById('add-plan-feature-row');
    if (!wrap || !btn) return;

    function nextIndex() {
        var n = parseInt(wrap.getAttribute('data-next-index') || '0', 10);
        if (isNaN(n)) n = 0;
        return n;
    }

    function setNextIndex(i) {
        wrap.setAttribute('data-next-index', String(i));
    }

    function rowCount() {
        return wrap.querySelectorAll('[data-row]').length;
    }

    btn.addEventListener('click', function () {
        var i = nextIndex();
        var row = document.createElement('div');
        row.className = 'plan-feature-row flex flex-wrap gap-2 items-center rounded-md border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-2';
        row.setAttribute('data-row', '');
        row.innerHTML =
            '<input type="text" name="features[' + i + '][key]" value="" placeholder="key" class="flex-1 min-w-[12rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />' +
            '<input type="text" name="features[' + i + '][value]" value="" placeholder="value" class="flex-1 min-w-[8rem] rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />' +
            '<button type="button" class="remove-plan-feature-row text-sm text-red-600 dark:text-red-400 hover:underline shrink-0">' + removeLabel + '</button>';
        wrap.appendChild(row);
        setNextIndex(i + 1);
    });

    wrap.addEventListener('click', function (e) {
        var t = e.target.closest('.remove-plan-feature-row');
        if (!t || !wrap.contains(t)) return;
        var row = t.closest('[data-row]');
        if (!row) return;
        if (rowCount() > 1) {
            row.remove();
            return;
        }
        row.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
    });
})();
</script>
@endsection
