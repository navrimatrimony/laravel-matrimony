@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8">
    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Secure Suchak QR</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Masked Candidate Preview</h1>
            </div>
            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                Contact masked
            </div>
        </div>

        <dl class="mt-6 grid gap-4 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Reference</dt>
                <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $candidateSummary['candidate_reference'] ?? 'masked-candidate' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Age range</dt>
                <dd class="mt-1">{{ $candidateSummary['basic']['age_range'] ?? 'Not available' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Height range</dt>
                <dd class="mt-1">{{ $candidateSummary['basic']['height_range'] ?? 'Not available' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Education</dt>
                <dd class="mt-1">{{ $candidateSummary['education']['highest'] ?? 'Not available' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Occupation</dt>
                <dd class="mt-1">{{ $candidateSummary['occupation']['broad'] ?? 'Not available' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Broad location</dt>
                <dd class="mt-1">{{ collect([$candidateSummary['location']['city'] ?? null, $candidateSummary['location']['district'] ?? null])->filter()->implode(', ') ?: 'Not available' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Community</dt>
                <dd class="mt-1">{{ collect([$candidateSummary['community']['religion'] ?? null, $candidateSummary['community']['caste'] ?? null])->filter()->implode(' / ') ?: 'Not available' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">QR expires</dt>
                <dd class="mt-1">{{ $qrToken->expires_at?->format('Y-m-d H:i') ?: 'Not configured' }}</dd>
            </div>
        </dl>

        <div class="mt-6 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            This QR preview intentionally hides direct contact details, exact address, profile photo, and identity fields.
        </div>
    </section>
</div>
@endsection
