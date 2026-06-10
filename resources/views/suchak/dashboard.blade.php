@extends('layouts.app')

@php
    $statusTone = match ($suchakAccount->verification_status) {
        \App\Models\SuchakAccount::VERIFICATION_VERIFIED => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
        \App\Models\SuchakAccount::VERIFICATION_SUSPENDED,
        \App\Models\SuchakAccount::VERIFICATION_REJECTED => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
        default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    };
    $fieldLabels = collect($allowedSuggestionFields)
        ->mapWithKeys(fn (string $field) => [$field => ucwords(str_replace('_', ' ', $field))])
        ->all();
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    @if (session('qr_url_path'))
        <section class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="font-semibold">Secure QR link generated</p>
                    <p class="mt-1 break-all font-mono text-xs">{{ url(session('qr_url_path')) }}</p>
                </div>
                <a href="{{ url(session('qr_url_path')) }}" target="_blank" rel="noopener" class="inline-flex justify-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Open QR preview
                </a>
            </div>
        </section>
    @endif

    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ $suchakAccount->office_name ?: $suchakAccount->suchak_name }}</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Dashboard</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Manage represented profiles, source biodata, masked discovery, collaborations, and governed profile update suggestions.
            </p>
        </div>
        <div class="rounded-md border px-4 py-3 text-sm font-semibold {{ $statusTone }}">
            Verification: {{ ucfirst($suchakAccount->verification_status) }}
            <span class="ml-2 font-normal">Public: {{ ucfirst($suchakAccount->public_status) }}</span>
        </div>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Represented</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['representations_total'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Active consent</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['representations_active'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Source links</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['source_links'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Pending collaborations</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['pending_collaborations'] }}</div>
        </div>
    </div>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Suchak Quick Links</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">नवीन Suchak साठी सर्व मुख्य कामांचे सोपे links.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('suchak.home') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Suchak Centre</a>
                <a href="{{ route('suchak.intakes.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Customer biodata entry</a>
                <a href="{{ route('suchak.search.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Masked search</a>
            </div>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-4">
            <div class="rounded-md bg-gray-50 p-4 text-sm dark:bg-gray-900">
                <p class="font-semibold text-gray-900 dark:text-gray-100">1. Account status</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">
                    {{ $suchakAccount->isVerified() ? 'Approved. You can start Suchak work.' : 'Pending. Admin approval is required before customer entry.' }}
                </p>
            </div>
            <a href="{{ route('suchak.intakes.create') }}" class="rounded-md bg-gray-50 p-4 text-sm hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950">
                <p class="font-semibold text-gray-900 dark:text-gray-100">2. Customer entry</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">Create intake source: customer चा biodata paste किंवा upload करा.</p>
            </a>
            <a href="{{ route('suchak.search.index') }}" class="rounded-md bg-gray-50 p-4 text-sm hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950">
                <p class="font-semibold text-gray-900 dark:text-gray-100">3. Search</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">Masked profiles शोधा, contact leak नाही.</p>
            </a>
            <a href="{{ route('suchak.home') }}" class="rounded-md bg-gray-50 p-4 text-sm hover:bg-gray-100 dark:bg-gray-900 dark:hover:bg-gray-950">
                <p class="font-semibold text-gray-900 dark:text-gray-100">4. Help links</p>
                <p class="mt-2 text-gray-600 dark:text-gray-300">Registration, OTP, admin approval links.</p>
            </a>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Represented Profiles</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Only masked candidate information is shown on this work surface.</p>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($representationCards as $card)
                        @php
                            $representation = $card['representation'];
                            $summary = $card['summary'];
                        @endphp
                        <article class="p-5">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $summary['candidate_reference'] ?? 'masked-candidate' }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                        <span class="rounded-full bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ ucfirst($representation->representation_status) }}</span>
                                        <span class="rounded-full bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">Consent: {{ ucfirst($representation->consent_status) }}</span>
                                    </div>
                                    <dl class="mt-4 grid gap-3 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Age</dt>
                                            <dd>{{ $summary['basic']['age_range'] ?? 'Not available' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Education</dt>
                                            <dd>{{ $summary['education']['highest'] ?? 'Not available' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Location</dt>
                                            <dd>{{ collect([$summary['location']['city'] ?? null, $summary['location']['district'] ?? null])->filter()->implode(', ') ?: 'Broad location unavailable' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Profile state</dt>
                                            <dd>{{ ucfirst((string) ($representation->matrimonyProfile?->lifecycle_state ?? 'unknown')) }}</dd>
                                        </div>
                                    </dl>
                                </div>
                                <div class="flex min-w-[16rem] flex-col gap-3">
                                    <form method="POST" action="{{ route('suchak.representations.exports.store', $representation) }}">
                                        @csrf
                                        <button type="submit" @disabled(! $card['can_export']) class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600 dark:disabled:bg-gray-700 dark:disabled:text-gray-300">
                                            Generate PDF/QR
                                        </button>
                                    </form>

                                    @if ($card['can_suggest_updates'] && count($fieldLabels) > 0)
                                        <form method="POST" action="{{ route('suchak.representations.profile-update-suggestions.store', $representation) }}" class="space-y-2">
                                            @csrf
                                            <label class="sr-only" for="field_key_{{ $representation->id }}">Field</label>
                                            <select id="field_key_{{ $representation->id }}" name="field_key" required class="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                @foreach ($fieldLabels as $fieldKey => $fieldLabel)
                                                    <option value="{{ $fieldKey }}" @selected(old('field_key') === $fieldKey)>{{ $fieldLabel }}</option>
                                                @endforeach
                                            </select>
                                            <label class="sr-only" for="suggested_value_{{ $representation->id }}">Suggested value</label>
                                            <input id="suggested_value_{{ $representation->id }}" name="suggested_value" value="{{ old('suggested_value') }}" maxlength="4000" placeholder="Suggested value" required class="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                            <button type="submit" class="w-full rounded-md border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-200 dark:hover:bg-indigo-950/40">
                                                Suggest profile update
                                            </button>
                                        </form>
                                    @else
                                        <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                            Update suggestions need verified Suchak status and active candidate consent.
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="p-6 text-sm text-gray-600 dark:text-gray-300">
                            No represented profiles yet. Create an intake source, then complete the existing review and consent flow.
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Incoming Collaborations</h2>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($pendingCollaborations as $collaboration)
                        <article class="flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Collaboration request #{{ $collaboration->id }}</p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $collaboration->message ?: 'No message provided.' }}</p>
                            </div>
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('suchak.collaborations.accept', $collaboration) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Accept</button>
                                </form>
                                <form method="POST" action="{{ route('suchak.collaborations.reject', $collaboration) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Reject</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <div class="p-6 text-sm text-gray-600 dark:text-gray-300">No incoming collaboration requests are pending.</div>
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Billing & Limits</h2>
                @if ($activeSubscription?->suchakPlan)
                    <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $activeSubscription->suchakPlan->name }}</p>
                    <dl class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($featureLimits as $feature => $value)
                            <div class="flex justify-between gap-3">
                                <dt>{{ ucwords(str_replace('_', ' ', $feature)) }}</dt>
                                <dd class="font-semibold">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</dd>
                            </div>
                        @empty
                            <div>No feature limits configured.</div>
                        @endforelse
                    </dl>
                @else
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No active Suchak subscription is assigned.</p>
                @endif

                @if ($catalogPlans->isNotEmpty())
                    <div class="mt-5 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Visible catalog</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($catalogPlans as $plan)
                                <div class="rounded-md bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $plan->name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $plan->hasConfiguredPrice() ? $plan->currency.' '.$plan->price_amount : 'Manual assignment' }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Source Links</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($recentSourceLinks as $link)
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ ucfirst($link->source_status) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Intake #{{ $link->biodata_intake_id }} · {{ $link->created_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No source links yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent PDF/QR Records</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($recentExports as $export)
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">Export #{{ $export->id }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $export->created_at?->format('Y-m-d H:i') }} · QR records: {{ $export->qrTokens->count() }}
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No PDF/QR records yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Suggestions</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($recentSuggestions as $suggestion)
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ ucwords(str_replace('_', ' ', $suggestion->field_key)) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ ucfirst($suggestion->suggestion_status) }} · {{ $suggestion->created_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No profile update suggestions yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Activity</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($activityLogs as $activity)
                        <div class="text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $activity->action_type }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $activity->occurred_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No activity logged yet.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
