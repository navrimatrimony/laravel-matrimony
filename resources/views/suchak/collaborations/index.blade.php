@extends('layouts.app')

@php
    $label = fn (string $value) => ucwords(str_replace('_', ' ', $value));
    $summaryText = static function (array $summary, string $group, string $key, string $fallback = 'Not available'): string {
        $value = $summary[$group][$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    };
    $communityText = static function (array $summary): string {
        $value = collect([$summary['community']['religion'] ?? null, $summary['community']['caste'] ?? null])->filter()->implode(' / ');

        return $value !== '' ? $value : 'Not available';
    };
    $locationText = static function (array $summary): string {
        $value = collect([$summary['location']['city'] ?? null, $summary['location']['district'] ?? null])->filter()->implode(', ');

        return $value !== '' ? $value : 'Broad location unavailable';
    };
    $statusTone = static fn (string $status): string => match ($status) {
        \App\Models\SuchakCollaborationRequest::STATUS_ACCEPTED => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
        \App\Models\SuchakCollaborationRequest::STATUS_REJECTED,
        \App\Models\SuchakCollaborationRequest::STATUS_EXPIRED,
        \App\Models\SuchakCollaborationRequest::STATUS_CANCELLED => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200',
        default => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    };
    $quickFilters = [
        ['label' => 'All', 'params' => []],
        ['label' => 'Incoming pending', 'params' => ['direction' => 'incoming', 'status' => \App\Models\SuchakCollaborationRequest::STATUS_PENDING]],
        ['label' => 'Outgoing pending', 'params' => ['direction' => 'outgoing', 'status' => \App\Models\SuchakCollaborationRequest::STATUS_PENDING]],
        ['label' => 'Accepted', 'params' => ['status' => \App\Models\SuchakCollaborationRequest::STATUS_ACCEPTED]],
        ['label' => 'Overdue', 'params' => ['overdue' => 1]],
        ['label' => 'Expired/Rejected', 'params' => ['status_group' => 'closed']],
    ];
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <a href="{{ route('suchak.dashboard') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Back to dashboard</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">Collaboration Center</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Review profile-specific Suchak-to-Suchak requests, masked match context, and commission acknowledgement before contact exchange.
            </p>
        </div>
        <form method="GET" action="{{ route('suchak.collaborations.index') }}" class="flex flex-wrap items-center gap-2">
            <select name="direction" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <option value="">Any direction</option>
                <option value="incoming" @selected($direction === 'incoming')>Incoming</option>
                <option value="outgoing" @selected($direction === 'outgoing')>Outgoing</option>
            </select>
            <select name="status" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <option value="">All statuses</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ $label($statusOption) }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Filter</button>
        </form>
    </div>

    @if (session('success'))
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Incoming pending</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['incoming_pending']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Outgoing pending</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['outgoing_pending']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Accepted</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['accepted']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Overdue</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($stats['overdue']) }}</div>
        </div>
    </div>

    <div class="mb-6 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-100">
        Current collaboration response timeout: <span class="font-semibold">{{ $collaborationSlaDays }} days</span>
    </div>

    <div class="mb-6 flex flex-wrap gap-2">
        @foreach ($quickFilters as $filter)
            @php
                $params = $filter['params'];
                $active = ($params['direction'] ?? null) === $direction
                    && ($params['status'] ?? null) === $status
                    && ($params['status_group'] ?? null) === ($statusGroup ?? null)
                    && (bool) ($params['overdue'] ?? false) === (bool) ($overdue ?? false)
                    && ! (($params === []) && ($direction !== null || $status !== null || ($statusGroup ?? null) !== null));
                if ($params === []) {
                    $active = $direction === null && $status === null && ($statusGroup ?? null) === null && ! ($overdue ?? false);
                }
                $urlParams = $params;
            @endphp
            <a href="{{ route('suchak.collaborations.index', $urlParams) }}" class="rounded-md border px-3 py-2 text-sm font-semibold {{ $active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800' }}">
                {{ $filter['label'] }}
            </a>
        @endforeach
    </div>

    @if (($suggestedOpportunities ?? collect())->isNotEmpty())
        <section class="mb-6 rounded-lg border border-emerald-200 bg-white p-5 shadow-sm dark:border-emerald-900 dark:bg-gray-800">
            <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Suggested opportunities</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Deterministic masked signals only. No AI score, rating, or best-match claim.</p>
                </div>
                <span class="rounded-md bg-emerald-50 px-3 py-2 text-xs font-semibold uppercase text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">Contact masked</span>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($suggestedOpportunities as $opportunity)
                    @php
                        $summary = $opportunity['target_summary'] ?? [];
                        $reasons = collect($opportunity['reasons'] ?? [])->filter()->values();
                        $warnings = collect($opportunity['warnings'] ?? [])->filter()->values();
                    @endphp
                    <article class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $opportunity['target_candidate_reference'] }}</p>
                                <h3 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $opportunity['fit_label'] ?? 'Possible preliminary fit' }}</h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $opportunity['fit_summary'] ?? $opportunity['reason'] }}</p>
                            </div>
                            <span class="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                                Request will go to: {{ $opportunity['target_suchak_label'] }}
                            </span>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div class="rounded-md bg-white p-3 text-sm dark:bg-gray-950">
                                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Your side</div>
                                <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $opportunity['requesting_candidate_reference'] }}</div>
                            </div>
                            <div class="rounded-md bg-white p-3 text-sm dark:bg-gray-950">
                                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Target side</div>
                                <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $opportunity['target_candidate_reference'] }}</div>
                            </div>
                        </div>

                        <dl class="mt-4 grid gap-2 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
                            <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Age</dt><dd>{{ $summaryText($summary, 'basic', 'age_range') }}</dd></div>
                            <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Height</dt><dd>{{ $summaryText($summary, 'basic', 'height_range') }}</dd></div>
                            <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Marital status</dt><dd>{{ $summaryText($summary, 'basic', 'marital_status') }}</dd></div>
                            <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Community</dt><dd>{{ $communityText($summary) }}</dd></div>
                            <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Location</dt><dd>{{ $locationText($summary) }}</dd></div>
                            <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Education</dt><dd>{{ $summaryText($summary, 'education', 'highest') }}</dd></div>
                            <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Occupation</dt><dd>{{ $summaryText($summary, 'occupation', 'broad') }}</dd></div>
                        </dl>

                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
                                <div class="font-semibold">Reasons</div>
                                <ul class="mt-1 list-disc space-y-1 pl-4">
                                    @foreach ($reasons as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            @if ($warnings->isNotEmpty())
                                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                    <div class="font-semibold">Review notes</div>
                                    <ul class="mt-1 list-disc space-y-1 pl-4">
                                        @foreach ($warnings as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        <div class="mt-4 rounded-md bg-white px-3 py-2 text-xs text-gray-600 dark:bg-gray-950 dark:text-gray-300">
                            Candidate contact and identity remain masked until governed collaboration steps allow exchange.
                        </div>
                        <form method="POST" action="{{ route('suchak.collaborations.store') }}" class="mt-4 space-y-3">
                            @csrf
                            <input type="hidden" name="requesting_representation_id" value="{{ $opportunity['requesting_representation_id'] }}">
                            <input type="hidden" name="target_representation_id" value="{{ $opportunity['target_representation_id'] }}">
                            <input type="hidden" name="split_type" value="{{ $opportunity['split_type'] }}">
                            <input type="hidden" name="currency" value="{{ $opportunity['currency'] }}">
                            <textarea name="message" rows="2" maxlength="2000" placeholder="Do not write phone/email/contact details here." class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"></textarea>
                            <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="commission_ack" value="1" required class="mt-1">
                                <span>{{ \App\Models\SuchakCommissionAgreement::MVP_ACK_TEXT }}</span>
                            </label>
                            <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Send collaboration request to this Suchak</button>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <div class="space-y-6">
        @forelse ($collaborations as $collaboration)
            @php
                $isRequester = (int) $collaboration->requesting_suchak_account_id === (int) $suchakAccount->id;
                $isTarget = (int) $collaboration->target_suchak_account_id === (int) $suchakAccount->id;
                $agreement = $collaboration->commissionAgreement;
                $collectorAccountId = (int) ($agreement?->collector_suchak_account_id ?? $collaboration->target_suchak_account_id);
                $collectorName = trim((string) ($agreement?->collectorSuchakAccount?->suchak_name ?? ''));
                $collectorLabel = '#'.$collectorAccountId.($collectorName !== '' ? ' '.$collectorName : '');
                $isAcceptedWithAck = $collaboration->status === \App\Models\SuchakCollaborationRequest::STATUS_ACCEPTED
                    && $agreement?->isAcceptedByBothSides();
                $isLockedCollector = $isAcceptedWithAck && $collectorAccountId === (int) $suchakAccount->id;
                $isPending = $collaboration->status === \App\Models\SuchakCollaborationRequest::STATUS_PENDING;
                $isOverdue = $isPending && $collaboration->expires_at?->isPast();
                $expiresSoon = $isPending && ! $isOverdue && $collaboration->expires_at?->lessThanOrEqualTo(now()->addDay());
                $requestingName = trim((string) ($collaboration->requestingSuchakAccount?->suchak_name ?? 'Requesting Suchak'));
                $targetName = trim((string) ($collaboration->targetSuchakAccount?->suchak_name ?? 'Target Suchak'));
                $headerText = $isTarget ? 'Incoming request from '.$requestingName : 'Outgoing request to '.$targetName;
                $summaries = $collaborationSummaries[$collaboration->id] ?? [];
                $requestingSummary = $summaries['requesting'] ?? [];
                $targetSummary = $summaries['target'] ?? [];
                $candidateBlocks = [
                    [
                        'label' => 'Your side',
                        'summary' => $isTarget ? $targetSummary : $requestingSummary,
                        'profile_id' => $isTarget ? $collaboration->target_matrimony_profile_id : $collaboration->requesting_matrimony_profile_id,
                        'representation_id' => $isTarget ? $collaboration->target_representation_id : $collaboration->requesting_representation_id,
                    ],
                    [
                        'label' => 'Other side',
                        'summary' => $isTarget ? $requestingSummary : $targetSummary,
                        'profile_id' => $isTarget ? $collaboration->requesting_matrimony_profile_id : $collaboration->target_matrimony_profile_id,
                        'representation_id' => $isTarget ? $collaboration->requesting_representation_id : $collaboration->target_representation_id,
                    ],
                ];
            @endphp

            <article class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $headerText }}</h2>
                                <span class="rounded-md border px-2.5 py-1 text-xs font-semibold {{ $statusTone($collaboration->status) }}">{{ $label($collaboration->status) }}</span>
                                @if ($isOverdue)
                                    <span class="rounded-md bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-800 dark:bg-red-950/40 dark:text-red-100">Overdue</span>
                                @elseif ($expiresSoon)
                                    <span class="rounded-md bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-100">Expires soon</span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Requested {{ $collaboration->requested_at?->format('Y-m-d H:i') ?: '-' }} · Expires {{ $collaboration->expires_at?->format('Y-m-d H:i') ?: '-' }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Support reference: Collaboration #{{ $collaboration->id }}</p>
                        </div>
                        @if ($isOverdue)
                            <form method="POST" action="{{ route('suchak.collaborations.expire', $collaboration) }}">
                                @csrf
                                <button type="submit" class="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">Mark as expired</button>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="grid gap-6 p-5 lg:grid-cols-3">
                    <section>
                        <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Candidates</h3>
                        <div class="mt-3 space-y-3">
                            @foreach ($candidateBlocks as $block)
                                @php($summary = $block['summary'])
                                <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $block['label'] }}</div>
                                    <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $summary['candidate_reference'] ?? 'Masked candidate' }}</div>
                                    <dl class="mt-2 grid gap-1 text-gray-700 dark:text-gray-300">
                                        <div class="flex justify-between gap-3"><dt>Age</dt><dd class="font-medium">{{ $summaryText($summary, 'basic', 'age_range') }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Height</dt><dd class="font-medium">{{ $summaryText($summary, 'basic', 'height_range') }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Marital</dt><dd class="font-medium">{{ $summaryText($summary, 'basic', 'marital_status') }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Community</dt><dd class="text-right font-medium">{{ $communityText($summary) }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Location</dt><dd class="text-right font-medium">{{ $locationText($summary) }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Education</dt><dd class="text-right font-medium">{{ $summaryText($summary, 'education', 'highest') }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Occupation</dt><dd class="text-right font-medium">{{ $summaryText($summary, 'occupation', 'broad') }}</dd></div>
                                    </dl>
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Support: Profile #{{ $block['profile_id'] }} · Representation #{{ $block['representation_id'] }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Message from Suchak</div>
                            <p class="mt-1">{{ $collaboration->message ?: 'No message added.' }}</p>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Commission / credit agreement</h3>
                        <dl class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                            <div class="flex justify-between gap-3">
                                <dt>Collector</dt>
                                <dd class="text-right font-semibold">{{ $agreement ? $collectorLabel : '-' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt>Split type</dt>
                                <dd class="font-semibold">{{ $agreement ? $label($agreement->split_type) : '-' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt>Acknowledgement status</dt>
                                <dd class="font-semibold">{{ $agreement ? $label($agreement->agreement_status) : '-' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt>Groom side</dt>
                                <dd>{{ $agreement?->groom_side_share !== null ? $agreement->groom_side_share.'%' : '-' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt>Bride side</dt>
                                <dd>{{ $agreement?->bride_side_share !== null ? $agreement->bride_side_share.'%' : '-' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt>Fixed amount</dt>
                                <dd>{{ $agreement?->fixed_amount !== null ? $agreement->currency.' '.$agreement->fixed_amount : '-' }}</dd>
                            </div>
                        </dl>

                        @if ($isRequester && $isPending && ! $isOverdue)
                            <form method="POST" action="{{ route('suchak.collaborations.commission.update', $collaboration) }}" class="mt-4 space-y-3 rounded-md bg-gray-50 p-3 dark:bg-gray-900">
                                @csrf
                                <p class="text-xs text-gray-500 dark:text-gray-400">Changing terms resets the other side acknowledgement.</p>
                                <div>
                                    <label class="block text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Split type</label>
                                    <select name="split_type" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        @foreach ($splitTypes as $splitType)
                                            <option value="{{ $splitType }}" @selected(($agreement?->split_type ?? \App\Models\SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED) === $splitType)>{{ $label($splitType) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <input type="number" name="groom_side_share" step="0.01" min="0" max="100" value="{{ $agreement?->groom_side_share }}" placeholder="Groom %" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    <input type="number" name="bride_side_share" step="0.01" min="0" max="100" value="{{ $agreement?->bride_side_share }}" placeholder="Bride %" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    <input type="number" name="fixed_amount" step="0.01" min="0.01" value="{{ $agreement?->fixed_amount }}" placeholder="Fixed amount" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    <input type="text" name="currency" maxlength="3" value="{{ $agreement?->currency ?: 'INR' }}" class="rounded-md border-gray-300 text-sm uppercase dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                </div>
                                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Update commission terms</button>
                            </form>
                        @endif
                    </section>

                    <section>
                        <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Decision</h3>
                        @if ($isTarget && $isPending && ! $isOverdue)
                            <div class="mt-3 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-900 dark:border-indigo-900 dark:bg-indigo-950/30 dark:text-indigo-100">
                                Accept means you agree to discuss this match and acknowledge commission/credit sharing. Contact exchange opens only after required acknowledgement is complete.
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('suchak.collaborations.accept', $collaboration) }}">
                                    @csrf
                                    <button type="submit" onclick="return confirm('Accept this collaboration request? Contact exchange opens only after required acknowledgement is complete.')" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Review and accept</button>
                                </form>
                                <form method="POST" action="{{ route('suchak.collaborations.reject', $collaboration) }}">
                                    @csrf
                                    <button type="submit" onclick="return confirm('Reject this collaboration request?')" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Reject request</button>
                                </form>
                            </div>
                        @elseif ($isAcceptedWithAck)
                            <div class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
                                Collaboration accepted. Governed contact exchange is now allowed for this collaboration. Candidate/family direct contact must still follow platform privacy rules.
                            </div>
                            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                                <li>Coordinate with the other Suchak.</li>
                                <li>Record follow-up / ledger only if relevant.</li>
                                <li>Use dispute reference if payment or credit issue occurs.</li>
                            </ol>
                            <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                Dispute reference: Collaboration #{{ $collaboration->id }}
                                @if (auth()->user()?->isAnyAdmin() && \Illuminate\Support\Facades\Route::has('admin.suchak.safety.index'))
                                    <a href="{{ route('admin.suchak.safety.index', ['dispute_type' => \App\Models\SuchakDispute::TYPE_PAYMENT_LEDGER]) }}" class="ml-1 font-semibold underline">Open safety center</a>
                                @endif
                            </div>
                        @else
                            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No direct action available for this state.</p>
                        @endif

                        @if ($isAcceptedWithAck && $isLockedCollector)
                            <form method="POST" action="{{ route('suchak.collaborations.ledger-entries.store', $collaboration) }}" class="mt-4 space-y-3 rounded-md bg-gray-50 p-3 dark:bg-gray-900">
                                @csrf
                                <input type="hidden" name="payment_collector" value="{{ \App\Models\SuchakPaymentContext::COLLECTOR_SUCHAK }}">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <select name="entry_type" required class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        @foreach ($ledgerTypeOptions as $entryType)
                                            <option value="{{ $entryType }}">{{ $label($entryType) }}</option>
                                        @endforeach
                                    </select>
                                    <select name="status" required class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                        @foreach ($ledgerStatusOptions as $entryStatus)
                                            <option value="{{ $entryStatus }}">{{ $label($entryStatus) }}</option>
                                        @endforeach
                                    </select>
                                    <div class="rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">Payment collector: {{ $collectorLabel }}</div>
                                    <input type="number" name="amount" step="0.01" min="0" placeholder="Amount" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    <input type="text" name="currency" maxlength="3" value="INR" class="rounded-md border-gray-300 text-sm uppercase dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    <input type="date" name="due_date" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    <input type="datetime-local" name="paid_at" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                </div>
                                <textarea name="note" rows="2" maxlength="2000" placeholder="Private ledger note without phone/email" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"></textarea>
                                <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Link ledger entry</button>
                            </form>

                            <div class="mt-4 space-y-2">
                                @forelse ($collaboration->ledgerEntries as $entry)
                                    <div class="rounded-md border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $label($entry->entry_type) }} · {{ $label($entry->status) }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $entry->amount !== null ? $entry->currency.' '.$entry->amount : 'No amount' }} · Profile #{{ $entry->matrimony_profile_id }}</div>
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No private ledger entries linked by this Suchak yet.</p>
                                @endforelse
                            </div>
                        @elseif ($isAcceptedWithAck)
                            <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-300">Only the locked collector Suchak can record collaboration income for this request.</p>
                        @endif
                    </section>
                </div>
            </article>
        @empty
            <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                No collaboration requests found.
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $collaborations->links() }}
    </div>
</div>
@endsection
