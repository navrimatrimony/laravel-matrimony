@extends('layouts.app')

@php
    $suchakText = \App\Support\Suchak\SuchakLocalizedText::class;
    $localizedText = \App\Support\LocalizedText::class;
    $label = fn (string $value) => $suchakText::label($value);
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ $suchakAccount->suchak_name }}</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Offline Camps</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Manage offline biodata drives through governed intake links, source tags, package assignments, and conversion reports.
            </p>
        </div>
        <a href="{{ route('suchak.dashboard') }}" class="inline-flex justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">
            Dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-1">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Create Camp / Drive</h2>
            <form method="POST" action="{{ route('suchak.offline-camps.store') }}" class="mt-4 space-y-3">
                @csrf
                <input name="camp_name" value="{{ old('camp_name') }}" placeholder="June biodata drive" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <input name="camp_name_mr" value="{{ old('camp_name_mr') }}" placeholder="Marathi camp name" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <input name="source_tag" value="{{ old('source_tag') }}" placeholder="jun_drive_2026" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <select name="camp_type" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($campTypes as $type)
                        <option value="{{ $type }}">{{ $label($type) }}</option>
                    @endforeach
                </select>
                <input name="location_label" value="{{ old('location_label') }}" placeholder="Area / venue label" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <input name="location_label_mr" value="{{ old('location_label_mr') }}" placeholder="Marathi area / venue label" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <div class="grid gap-3 sm:grid-cols-2">
                    <input name="camp_date" type="date" value="{{ old('camp_date') }}" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <input name="expected_intake_count" type="number" min="0" value="{{ old('expected_intake_count', 0) }}" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <textarea name="privacy_note" rows="4" placeholder="Privacy-safe operating note. Do not store phone, email, UPI, or direct payment details." class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ old('privacy_note') }}</textarea>
                <textarea name="privacy_note_mr" rows="4" placeholder="Marathi privacy-safe operating note" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ old('privacy_note_mr') }}</textarea>
                <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900">Create camp</button>
            </form>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Camp List</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @forelse ($summary['camps'] as $camp)
                    <a href="{{ route('suchak.offline-camps.index', ['camp' => $camp->id]) }}" class="rounded-lg border border-gray-200 bg-gray-50 p-4 hover:border-indigo-300 hover:bg-indigo-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-indigo-500">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $localizedText::column($camp, 'camp_name') }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ $camp->source_tag }} / {{ $label($camp->camp_type) }}</p>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $label($camp->camp_status) }}</span>
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <div><dt>Intakes</dt><dd class="font-semibold">{{ $camp->intake_links_count }}</dd></div>
                            <div><dt>Packages</dt><dd class="font-semibold">{{ $camp->package_assignments_count }}</dd></div>
                            <div><dt>Reports</dt><dd class="font-semibold">{{ $camp->conversion_reports_count }}</dd></div>
                        </dl>
                    </a>
                @empty
                    <p class="text-sm text-gray-500">No offline camps created yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    @if ($selectedCamp)
        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $localizedText::column($selectedCamp, 'camp_name') }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Source tag: {{ $selectedCamp->source_tag }}</p>
                </div>
                <form method="POST" action="{{ route('suchak.offline-camps.conversion-reports.generate', $selectedCamp) }}" class="flex flex-col gap-2 sm:flex-row">
                    @csrf
                    <input name="report_note" placeholder="Report note" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Generate conversion report</button>
                </form>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <form method="POST" enctype="multipart/form-data" action="{{ route('suchak.offline-camps.intakes.store', $selectedCamp) }}" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    @csrf
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Upload / Paste Biodata</h3>
                    <textarea name="raw_text" rows="5" placeholder="Paste raw biodata text for governed intake creation" class="mt-3 w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">{{ old('raw_text') }}</textarea>
                    <input name="file" type="file" class="mt-3 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                    <input name="link_note" placeholder="Optional privacy-safe link note" class="mt-3 w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                    <button type="submit" class="mt-3 rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900">Create governed intake link</button>
                </form>

                <form method="POST" action="{{ route('suchak.offline-camps.source-links.store', $selectedCamp) }}" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    @csrf
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Attach Existing Source Links</h3>
                    <div class="mt-3 max-h-48 space-y-2 overflow-y-auto rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-950">
                        @forelse ($summary['source_links'] as $sourceLink)
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="source_link_ids[]" value="{{ $sourceLink->id }}" class="rounded border-gray-300">
                                Source #{{ $sourceLink->id }} / Intake #{{ $sourceLink->biodata_intake_id }} / {{ $label($sourceLink->source_status) }}
                            </label>
                        @empty
                            <p class="text-sm text-gray-500">No unattached source links available.</p>
                        @endforelse
                    </div>
                    <input name="link_note" placeholder="Optional privacy-safe link note" class="mt-3 w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                    <button type="submit" class="mt-3 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Attach selected</button>
                </form>
            </div>
        </section>

        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Linked Camp Intakes</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Source</th>
                            <th class="py-2 pr-4">Duplicate check</th>
                            <th class="py-2 pr-4">Package assignment</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($selectedCamp->intakeLinks as $campLink)
                            <tr>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">Source #{{ $campLink->source_link_id }}</div>
                                    <div class="text-xs text-gray-500">Intake #{{ $campLink->biodata_intake_id }} / {{ $label($campLink->source_status_snapshot) }}</div>
                                </td>
                                <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">
                                    {{ $label($campLink->duplicate_check_status) }}
                                    @if ($campLink->privacy_safe_duplicate_hash)
                                        <div class="mt-1 font-mono text-xs text-gray-500">{{ Str::limit($campLink->privacy_safe_duplicate_hash, 18, '') }}</div>
                                    @endif
                                </td>
                                <td class="py-3 pr-4">
                                    <form method="POST" action="{{ route('suchak.offline-camps.intake-links.package-assignments.store', $campLink) }}" class="grid gap-2 md:grid-cols-3">
                                        @csrf
                                        <select name="service_package_id" class="rounded-md border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                            @foreach ($summary['packages'] as $package)
                                                <option value="{{ $package->id }}">{{ $localizedText::column($package, 'package_name') }} #{{ $package->id }}</option>
                                            @endforeach
                                        </select>
                                        <input name="assignment_note" placeholder="Assignment note" class="rounded-md border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">Assign</button>
                                    </form>
                                    @if ($campLink->packageAssignments->isNotEmpty())
                                        <div class="mt-2 space-y-1 text-xs text-gray-500">
                                            @foreach ($campLink->packageAssignments as $assignment)
                                                <div>{{ $localizedText::column($assignment->servicePackage, 'package_name') }} / {{ $label($assignment->assignment_status) }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-gray-500">No camp intakes linked yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Consent Pending List</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($consentPendingList as $item)
                        <article class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">Source #{{ $item['source_link']?->id }}</div>
                            <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $label($item['consent_status']) }}</div>
                            <div class="mt-1 text-xs text-gray-500">Hash-only duplicate ref: {{ $item['privacy_safe_duplicate_hash'] ? Str::limit($item['privacy_safe_duplicate_hash'], 18, '') : 'not available' }}</div>
                        </article>
                    @empty
                        <p class="text-sm text-gray-500">No consent pending camp sources.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Conversion Reports</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($selectedCamp->conversionReports->sortByDesc('generated_at') as $report)
                        <article class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $report->generated_at?->format('Y-m-d H:i') }}</div>
                            <dl class="mt-2 grid grid-cols-2 gap-2 text-gray-600 dark:text-gray-300">
                                <div><dt>Intakes</dt><dd class="font-semibold">{{ $report->total_intake_links }}</dd></div>
                                <div><dt>Duplicates</dt><dd class="font-semibold">{{ $report->possible_duplicate_links }}</dd></div>
                                <div><dt>Consent pending</dt><dd class="font-semibold">{{ $report->consent_pending_count }}</dd></div>
                                <div><dt>Packages</dt><dd class="font-semibold">{{ $report->package_assignment_count }}</dd></div>
                            </dl>
                            <p class="mt-2 text-xs text-gray-500">{{ $report->report_note }}</p>
                        </article>
                    @empty
                        <p class="text-sm text-gray-500">No conversion reports generated yet.</p>
                    @endforelse
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
