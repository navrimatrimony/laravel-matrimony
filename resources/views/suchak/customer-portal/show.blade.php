@extends('layouts.app')

@section('content')
@php
    $suchakText = \App\Support\Suchak\SuchakLocalizedText::class;
    $localizedText = \App\Support\LocalizedText::class;
    $package = $paymentRequest?->servicePackage;
    $agreement = $paymentRequest?->customerAgreement;
    $payments = $paymentRequest?->customerPayments ?? collect();
    $familyMembers = $customerContext?->familyMembers ?? collect();
    $corrections = $paymentRequest?->customerPaymentCorrections ?? collect();
    $overdueActions = $paymentRequest?->overdueServiceActions ?? collect();
@endphp

<div class="mx-auto max-w-5xl px-4 py-8">
    <div class="mb-6">
        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Suchak Customer Portal</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
            {{ $localizedText::column($package, 'package_name') ?: $localizedText::column($paymentRequest, 'request_title') ?: 'Customer service context' }}
        </h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Verify package, terms, payment, invoice, and receipt status for this Suchak customer context.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-5 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            {{ $errors->first() }}
        </div>
    @endif

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Portal Status</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::label($portalLink->portal_status) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Terms</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $agreement ? $suchakText::label($agreement->terms_status) : 'Not available' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Payment Request</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $paymentRequest ? $suchakText::label($paymentRequest->payment_status) : 'Not available' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Expires</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ optional($portalLink->expires_at)->format('d M Y, h:i A') ?? 'No expiry set' }}</div>
            </div>
        </div>
    </section>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Package And Terms</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $localizedText::column($agreement, 'agreement_title') ?: 'Agreement not available' }}</p>
            </div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                @if ($agreement)
                    Revision {{ $agreement->agreement_revision }}
                @endif
            </div>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Amount Due</div>
                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                    @if ($paymentRequest && $paymentRequest->amount_due !== null)
                        {{ $paymentRequest->currency ?? 'INR' }} {{ $paymentRequest->amount_due }}
                    @elseif ($agreement && $agreement->price_amount !== null)
                        {{ $agreement->currency ?? 'INR' }} {{ $agreement->price_amount }}
                    @else
                        To be confirmed
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Collector</div>
                <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $localizedText::column($paymentRequest, 'collector_disclosure') ?: 'Not available' }}</div>
            </div>
        </div>

        @if ($localizedText::column($agreement, 'agreement_body') !== '')
            <p class="mt-5 whitespace-pre-line border-t border-gray-200 pt-4 text-sm leading-6 text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ $localizedText::column($agreement, 'agreement_body') }}</p>
        @endif
    </section>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Payments And Documents</h2>
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            Platform-collected customers should not make direct Suchak payments. If any Suchak asks for payment outside this verified platform context, use your logged-in account to report it with evidence.
        </div>
        <div class="mt-4 space-y-4">
            @forelse ($payments as $payment)
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <div class="grid gap-4 sm:grid-cols-4">
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Status</div>
                            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::label($payment->payment_status) }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Received</div>
                            <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $payment->currency }} {{ $payment->amount_received }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Balance</div>
                            <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $payment->currency }} {{ $payment->balance_amount }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Mode</div>
                            <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $suchakText::label($payment->payment_mode) }}</div>
                        </div>
                    </div>

                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Documents</div>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($payment->documents as $document)
                                <div class="text-sm text-gray-900 dark:text-gray-100">
                                    {{ $suchakText::label($document->document_type) }}:
                                    <span class="font-semibold">{{ $document->document_number }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">issued {{ optional($document->issued_at)->format('d M Y') }}</span>
                                    @if ($document->verification_code)
                                        <a class="ml-2 font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100" href="{{ route('suchak.receipts.verify', ['code' => $document->verification_code]) }}">Verify receipt</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-300">No payment record has been posted for this request yet.</p>
            @endforelse
        </div>
    </section>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Corrections And Service Actions</h2>
        <div class="mt-4 grid gap-5 md:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Financial Corrections</h3>
                <div class="mt-3 space-y-2">
                    @forelse ($corrections as $correction)
                        <div class="text-sm text-gray-700 dark:text-gray-200">
                            {{ $suchakText::label($correction->correction_type) }}:
                            {{ $suchakText::label($correction->correction_status) }}
                            - {{ $correction->currency }} {{ $correction->amount }}
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No correction posted.</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Overdue Service Actions</h3>
                <div class="mt-3 space-y-2">
                    @forelse ($overdueActions as $action)
                        <div class="text-sm text-gray-700 dark:text-gray-200">
                            {{ $suchakText::label($action->action_type) }}:
                            {{ $suchakText::label($action->action_status) }}
                        </div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-300">No overdue service action.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Family And Payer Context</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @forelse ($familyMembers as $member)
                <div class="rounded-md bg-gray-50 p-4 text-sm dark:bg-gray-900">
                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $suchakText::label($member->member_role) }}</div>
                    <div class="mt-1 text-gray-700 dark:text-gray-200">Relationship: {{ $member->relationship_to_candidate ?? 'Not specified' }}</div>
                    <div class="mt-1 text-gray-700 dark:text-gray-200">Payer role: {{ $suchakText::label($member->payer_role) }}</div>
                    <div class="mt-1 text-gray-700 dark:text-gray-200">Status: {{ $suchakText::label($member->access_status) }}</div>
                </div>
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-300">No shared family member context has been linked yet.</p>
            @endforelse
        </div>
    </section>

    @if ($portalLink->portal_status === \App\Models\SuchakCustomerPortalLink::STATUS_ACTIVE)
        <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Claim Portal Link</h2>
            <form class="mt-4 grid gap-4 sm:grid-cols-2" method="POST" action="{{ route('suchak.customer-portal.claim', ['token' => $token]) }}">
                @csrf
                <div>
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-200" for="claimed_name">Name</label>
                    <input class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" id="claimed_name" name="claimed_name" maxlength="160" required>
                </div>
                <div>
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-200" for="claimed_relationship_to_candidate">Relationship</label>
                    <input class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" id="claimed_relationship_to_candidate" name="claimed_relationship_to_candidate" maxlength="80" required>
                </div>
                <div class="sm:col-span-2">
                    <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" type="submit">Claim</button>
                </div>
            </form>
        </section>
    @endif

    @if (in_array($portalLink->portal_status, [\App\Models\SuchakCustomerPortalLink::STATUS_ACTIVE, \App\Models\SuchakCustomerPortalLink::STATUS_CLAIMED], true))
        <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 shadow-sm dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <h2 class="text-base font-semibold">Revoke Portal Link</h2>
            <form class="mt-3 flex flex-col gap-3" method="POST" action="{{ route('suchak.customer-portal.revoke', ['token' => $token]) }}">
                @csrf
                <label class="font-semibold" for="revoke_reason">Reason</label>
                <textarea class="min-h-20 rounded-md border border-amber-300 px-3 py-2 text-sm text-gray-900 dark:border-amber-800 dark:bg-gray-900 dark:text-gray-100" id="revoke_reason" name="revoke_reason" maxlength="1000" required></textarea>
                <div>
                    <button class="rounded-md bg-amber-900 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-800 dark:bg-amber-200 dark:text-amber-950 dark:hover:bg-amber-100" type="submit">Revoke Access</button>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection
