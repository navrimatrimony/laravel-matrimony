@extends('layouts.app')

@php
    $label = fn (string $value) => ucwords(str_replace('_', ' ', $value));
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <a href="{{ route('suchak.dashboard') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Back to dashboard</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">Collaboration Center</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Manage Suchak-to-Suchak requests, commission acknowledgement, policy timeout, and private ledger linkage.
            </p>
        </div>
        <form method="GET" action="{{ route('suchak.collaborations.index') }}" class="flex flex-wrap items-center gap-2">
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

    <div class="space-y-6">
        @forelse ($collaborations as $collaboration)
            @php
                $isRequester = (int) $collaboration->requesting_suchak_account_id === (int) $suchakAccount->id;
                $isTarget = (int) $collaboration->target_suchak_account_id === (int) $suchakAccount->id;
                $agreement = $collaboration->commissionAgreement;
                $isAcceptedWithAck = $collaboration->status === \App\Models\SuchakCollaborationRequest::STATUS_ACCEPTED
                    && $agreement?->isAcceptedByBothSides();
                $isPending = $collaboration->status === \App\Models\SuchakCollaborationRequest::STATUS_PENDING;
                $isOverdue = $isPending && $collaboration->expires_at?->isPast();
            @endphp

            <article class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                Collaboration #{{ $collaboration->id }} · {{ $isRequester ? 'Outgoing' : 'Incoming' }}
                            </h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Status: <span class="font-semibold">{{ $label($collaboration->status) }}</span>
                                · Requested {{ $collaboration->requested_at?->format('Y-m-d H:i') }}
                                · Expires {{ $collaboration->expires_at?->format('Y-m-d H:i') }}
                            </p>
                        </div>
                        @if ($isOverdue)
                            <form method="POST" action="{{ route('suchak.collaborations.expire', $collaboration) }}">
                                @csrf
                                <button type="submit" class="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">Expire overdue</button>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="grid gap-6 p-5 lg:grid-cols-3">
                    <section>
                        <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Parties</h3>
                        <dl class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                            <div>
                                <dt class="font-semibold text-gray-900 dark:text-gray-100">Requesting Suchak</dt>
                                <dd>#{{ $collaboration->requesting_suchak_account_id }} {{ $collaboration->requestingSuchakAccount?->suchak_name }}</dd>
                                <dd>Profile #{{ $collaboration->requesting_matrimony_profile_id }} · Representation #{{ $collaboration->requesting_representation_id }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold text-gray-900 dark:text-gray-100">Target Suchak</dt>
                                <dd>#{{ $collaboration->target_suchak_account_id }} {{ $collaboration->targetSuchakAccount?->suchak_name }}</dd>
                                <dd>Profile #{{ $collaboration->target_matrimony_profile_id }} · Representation #{{ $collaboration->target_representation_id }}</dd>
                            </div>
                        </dl>
                        @if ($collaboration->message)
                            <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $collaboration->message }}</p>
                        @endif
                    </section>

                    <section>
                        <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Commission Agreement</h3>
                        <dl class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                            <div class="flex justify-between gap-3">
                                <dt>Split</dt>
                                <dd class="font-semibold">{{ $agreement ? $label($agreement->split_type) : '-' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt>Status</dt>
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
                                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Update commission</button>
                            </form>
                        @endif
                    </section>

                    <section>
                        <h3 class="text-sm font-semibold uppercase text-gray-500 dark:text-gray-400">Actions</h3>
                        @if ($isTarget && $isPending && ! $isOverdue)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('suchak.collaborations.accept', $collaboration) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Accept</button>
                                </form>
                                <form method="POST" action="{{ route('suchak.collaborations.reject', $collaboration) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Reject</button>
                                </form>
                            </div>
                        @elseif ($isAcceptedWithAck)
                            <p class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">Contact exchange gate is open for this collaboration.</p>
                        @else
                            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">No direct action available for this state.</p>
                        @endif

                        @if ($isAcceptedWithAck)
                            <form method="POST" action="{{ route('suchak.collaborations.ledger-entries.store', $collaboration) }}" class="mt-4 space-y-3 rounded-md bg-gray-50 p-3 dark:bg-gray-900">
                                @csrf
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
