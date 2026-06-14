@extends('layouts.admin')

@php
    $suchakText = \App\Support\Suchak\SuchakLocalizedText::class;
@endphp

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <a href="{{ route('admin.suchak.dashboard') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Back to Suchak dashboard</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Plan Catalog</h1>
                <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                    Create and manage only Suchak business plans. Member subscription plans remain separate.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.suchak.accounts.index') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Assign to account</a>
                <a href="{{ route('admin.suchak.settings.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">Settings</a>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <p class="font-semibold">Please fix the highlighted inputs.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Free trial</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($policySummary['free_trial_days']) }} days</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Grace period</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($policySummary['grace_period_days']) }} days</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pricing mode</div>
            <div class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $suchakText::label($policySummary['pricing_mode']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payment mode</div>
            <div class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $suchakText::label($policySummary['payment_mode']) }}</div>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Create Suchak Plan</h2>
        <form method="POST" action="{{ route('admin.suchak.plans.store') }}" class="mt-5 space-y-5">
            @csrf
            <div class="grid gap-4 lg:grid-cols-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_name">Name</label>
                    <input id="new_plan_name" name="name" value="{{ old('name') }}" required maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_name_mr">Name Marathi</label>
                    <input id="new_plan_name_mr" name="name_mr" value="{{ old('name_mr') }}" maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_slug">Slug</label>
                    <input id="new_plan_slug" name="slug" value="{{ old('slug') }}" required maxlength="80" placeholder="suchak-growth" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_price">Price amount</label>
                    <input id="new_plan_price" name="price_amount" value="{{ old('price_amount') }}" inputmode="decimal" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_currency">Currency</label>
                    <input id="new_plan_currency" name="currency" value="{{ old('currency', 'INR') }}" maxlength="3" class="mt-1 w-full rounded-md border-gray-300 text-sm uppercase dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_billing_period_days">Plan duration (days)</label>
                    <input id="new_plan_billing_period_days" name="billing_period_days" value="{{ old('billing_period_days', 30) }}" type="number" min="1" max="3650" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_description">Description</label>
                    <textarea id="new_plan_description" name="description" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('description') }}</textarea>
                </div>
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_description_mr">Description Marathi</label>
                    <textarea id="new_plan_description_mr" name="description_mr" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('description_mr') }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_sort">Display order</label>
                    <input id="new_plan_sort" name="sort_order" value="{{ old('sort_order', 10) }}" type="number" min="0" max="65535" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div class="space-y-3">
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', '1')) class="rounded border-gray-300 text-indigo-600">
                        Active
                    </label>
                    <input type="hidden" name="is_visible" value="0">
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                        <input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', '1')) class="rounded border-gray-300 text-indigo-600">
                        Show this plan to Suchaks
                    </label>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Plan access and limits</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Set the numbers Suchaks will get. Use Yes or No for optional tools.</p>
                <div class="mt-3 grid gap-3 lg:grid-cols-3">
                    @foreach ($featureDefinitions as $key => $definition)
                        @php
                            $featureValue = old("features.$key.feature_value", $definition['default']);
                            $booleanFeatureValue = filter_var($featureValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                        @endphp
                        <div class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
                            <input type="hidden" name="features[{{ $key }}][feature_key]" value="{{ $key }}">
                            <input type="hidden" name="features[{{ $key }}][value_type]" value="{{ $definition['type'] }}">
                            <input type="hidden" name="features[{{ $key }}][is_enabled]" value="0">
                            <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                <input type="checkbox" name="features[{{ $key }}][is_enabled]" value="1" @checked(old("features.$key.is_enabled", '1')) class="rounded border-gray-300 text-indigo-600">
                                {{ $definition['label'] }}
                            </label>
                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $definition['help'] }}</p>
                            @if ($definition['type'] === \App\Models\SuchakPlanFeature::TYPE_BOOLEAN)
                                <label class="mt-3 block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400" for="new_feature_{{ $key }}_value">Available to Suchak?</label>
                                <select id="new_feature_{{ $key }}_value" name="features[{{ $key }}][feature_value]" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                    <option value="true" @selected($booleanFeatureValue === 'true')>Yes - include</option>
                                    <option value="false" @selected($booleanFeatureValue === 'false')>No - do not include</option>
                                </select>
                            @else
                                <label class="mt-3 block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400" for="new_feature_{{ $key }}_value">Number allowed</label>
                                <input id="new_feature_{{ $key }}_value" name="features[{{ $key }}][feature_value]" value="{{ $featureValue }}" type="number" min="0" step="1" inputmode="numeric" maxlength="255" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="new_plan_reason">Why are you creating this plan?</label>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">This note is saved for admin audit history.</p>
                <textarea id="new_plan_reason" name="reason" rows="2" required minlength="10" maxlength="500" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
            </div>

            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Create plan</button>
        </form>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Existing Suchak Plans</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Edit plan values here. Assignments happen from the Suchak account detail page.</p>
            </div>
            <a href="{{ route('admin.suchak.accounts.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Open accounts</a>
        </div>

        <div class="mt-5 space-y-5">
            @forelse ($plans as $plan)
                @php
                    $planFeatures = $plan->features->keyBy('feature_key');
                @endphp
                <article id="plan-{{ $plan->id }}" class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h3>
                            @if ($plan->name_mr)
                                <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $plan->name_mr }}</p>
                            @endif
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $plan->slug }} · {{ $plan->hasConfiguredPrice() ? $plan->currency.' '.$plan->price_amount : 'Manual assignment / price not configured' }} · {{ number_format($plan->billing_period_days ?? 30) }} days
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs font-semibold">
                            <span class="rounded-full px-2 py-1 {{ $plan->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100' : 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-100' }}">
                                {{ $plan->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <span class="rounded-full px-2 py-1 {{ $plan->is_visible ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-100' : 'bg-gray-100 text-gray-700 dark:bg-gray-900 dark:text-gray-200' }}">
                                {{ $plan->is_visible ? 'Visible' : 'Hidden' }}
                            </span>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.suchak.plans.update', $plan) }}" class="mt-4 space-y-4">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-4 lg:grid-cols-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_name">Name</label>
                                <input id="plan_{{ $plan->id }}_name" name="name" value="{{ old('name', $plan->name) }}" required maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_name_mr">Name Marathi</label>
                                <input id="plan_{{ $plan->id }}_name_mr" name="name_mr" value="{{ old('name_mr', $plan->name_mr) }}" maxlength="120" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_slug">Slug</label>
                                <input id="plan_{{ $plan->id }}_slug" name="slug" value="{{ old('slug', $plan->slug) }}" required maxlength="80" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_price">Price amount</label>
                                <input id="plan_{{ $plan->id }}_price" name="price_amount" value="{{ old('price_amount', $plan->price_amount) }}" inputmode="decimal" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_currency">Currency</label>
                                <input id="plan_{{ $plan->id }}_currency" name="currency" value="{{ old('currency', $plan->currency ?? 'INR') }}" maxlength="3" class="mt-1 w-full rounded-md border-gray-300 text-sm uppercase dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_billing_period_days">Plan duration (days)</label>
                                <input id="plan_{{ $plan->id }}_billing_period_days" name="billing_period_days" value="{{ old('billing_period_days', $plan->billing_period_days ?? 30) }}" type="number" min="1" max="3650" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div class="lg:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_description">Description</label>
                                <textarea id="plan_{{ $plan->id }}_description" name="description" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('description', $plan->description) }}</textarea>
                            </div>
                            <div class="lg:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_description_mr">Description Marathi</label>
                                <textarea id="plan_{{ $plan->id }}_description_mr" name="description_mr" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('description_mr', $plan->description_mr) }}</textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_sort">Display order</label>
                                <input id="plan_{{ $plan->id }}_sort" name="sort_order" value="{{ old('sort_order', $plan->sort_order) }}" type="number" min="0" max="65535" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div class="space-y-3">
                                <input type="hidden" name="is_active" value="0">
                                <label class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active)) class="rounded border-gray-300 text-indigo-600">
                                    Active
                                </label>
                                <input type="hidden" name="is_visible" value="0">
                                <label class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                                    <input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $plan->is_visible)) class="rounded border-gray-300 text-indigo-600">
                                    Show this plan to Suchaks
                                </label>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Plan access and limits</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Change the numbers Suchaks will get. Use Yes or No for optional tools.</p>
                        </div>
                        <div class="grid gap-3 lg:grid-cols-3">
                            @foreach ($featureDefinitions as $key => $definition)
                                @php
                                    $feature = $planFeatures->get($key);
                                    $featureEnabled = $feature?->is_enabled ?? false;
                                    $featureValue = $feature?->feature_value ?? $definition['default'];
                                    $submittedFeatureValue = old("features.$key.feature_value", $featureValue);
                                    $booleanFeatureValue = filter_var($submittedFeatureValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                                @endphp
                                <div class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
                                    <input type="hidden" name="features[{{ $key }}][feature_key]" value="{{ $key }}">
                                    <input type="hidden" name="features[{{ $key }}][value_type]" value="{{ $definition['type'] }}">
                                    <input type="hidden" name="features[{{ $key }}][is_enabled]" value="0">
                                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        <input type="checkbox" name="features[{{ $key }}][is_enabled]" value="1" @checked(old("features.$key.is_enabled", $featureEnabled)) class="rounded border-gray-300 text-indigo-600">
                                        {{ $definition['label'] }}
                                    </label>
                                    <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $definition['help'] }}</p>
                                    @if ($definition['type'] === \App\Models\SuchakPlanFeature::TYPE_BOOLEAN)
                                        <label class="mt-3 block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400" for="feature_{{ $plan->id }}_{{ $key }}_value">Available to Suchak?</label>
                                        <select id="feature_{{ $plan->id }}_{{ $key }}_value" name="features[{{ $key }}][feature_value]" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                            <option value="true" @selected($booleanFeatureValue === 'true')>Yes - include</option>
                                            <option value="false" @selected($booleanFeatureValue === 'false')>No - do not include</option>
                                        </select>
                                    @else
                                        <label class="mt-3 block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400" for="feature_{{ $plan->id }}_{{ $key }}_value">Number allowed</label>
                                        <input id="feature_{{ $plan->id }}_{{ $key }}_value" name="features[{{ $key }}][feature_value]" value="{{ $submittedFeatureValue }}" type="number" min="0" step="1" inputmode="numeric" maxlength="255" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_{{ $plan->id }}_reason">Why are you changing this plan?</label>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">This note is saved for admin audit history.</p>
                            <textarea id="plan_{{ $plan->id }}_reason" name="reason" rows="2" required minlength="10" maxlength="500" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        </div>

                        <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Save plan</button>
                    </form>
                </article>
            @empty
                <p class="rounded-md border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    No Suchak plans configured yet.
                </p>
            @endforelse
        </div>
    </section>
</div>
@endsection
