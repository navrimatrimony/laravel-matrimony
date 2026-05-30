@extends('layouts.admin-showcase')

@section('showcase_content')
@php
    $bulkLc = $bulkShowcaseLifecycle ?? 'draft';
    $summary = is_array($bulkResult ?? null) ? ($bulkResult['summary'] ?? null) : null;
    $groupedWarnings = is_array($bulkResult ?? null) ? ($bulkResult['grouped_warnings'] ?? []) : [];
    $noPhotoIds = array_flip($noPhotoProfileIds ?? []);
    $policy = $photoPolicyLabels ?? [];
@endphp
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Bulk Create Showcase Profiles (1–50)</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">
        @if ($bulkLc === 'active')
            Create multiple showcase profiles. Current <a href="{{ route('admin.auto-showcase-settings.edit') }}#bulk" class="text-indigo-600 dark:text-indigo-400 underline">Admin bulk</a> lifecycle is <strong>active</strong> — new profiles are visible in member search when completeness and visibility rules pass (no publish step).
        @else
            Create multiple showcase profiles. Current Admin bulk lifecycle is <strong>draft</strong> — new profiles are <strong>not visible in member search</strong> until you publish. Change this under <a href="{{ route('admin.auto-showcase-settings.edit') }}#bulk" class="text-indigo-600 dark:text-indigo-400 underline">Auto-showcase settings → Admin bulk</a>.
        @endif
    </p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    @if (session('success'))
        <div class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    @php $pool = $poolHealth ?? ['bucket_count' => 0, 'total_photos' => 0, 'exhausted_buckets' => 0, 'low_unused_buckets' => 0]; @endphp
    <div class="mb-6 rounded-xl border border-violet-200 bg-violet-50/50 px-4 py-4 dark:border-violet-900/50 dark:bg-violet-950/20">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm font-bold text-violet-950 dark:text-violet-100">{{ __('showcase_bulk.pool_health_title') }}</p>
                @if ((int) ($pool['bucket_count'] ?? 0) === 0)
                    <p class="mt-1 text-xs text-violet-900/90 dark:text-violet-200/90">{{ __('showcase_bulk.pool_health_empty') }}</p>
                @else
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-[10px] uppercase tracking-wide text-violet-800/70">{{ __('showcase_bulk.pool_health_buckets') }}</dt><dd class="font-bold text-gray-900 dark:text-gray-100">{{ $pool['bucket_count'] }}</dd></div>
                        <div><dt class="text-[10px] uppercase tracking-wide text-violet-800/70">{{ __('showcase_bulk.pool_health_total') }}</dt><dd class="font-bold text-gray-900 dark:text-gray-100">{{ $pool['total_photos'] }}</dd></div>
                        <div><dt class="text-[10px] uppercase tracking-wide text-amber-800/80">{{ __('showcase_bulk.pool_health_low') }}</dt><dd class="font-bold text-amber-800 dark:text-amber-200">{{ $pool['low_unused_buckets'] }}</dd></div>
                        <div><dt class="text-[10px] uppercase tracking-wide text-amber-800/80">{{ __('showcase_bulk.pool_health_exhausted') }}</dt><dd class="font-bold text-amber-800 dark:text-amber-200">{{ $pool['exhausted_buckets'] }}</dd></div>
                    </dl>
                @endif
            </div>
            <a href="{{ route('admin.showcase-photo-pool.index') }}" class="shrink-0 rounded-lg bg-violet-600 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-700">{{ __('showcase_bulk.pool_health_manage') }}</a>
        </div>
    </div>

    @if (is_array($summary))
        <div class="mb-6 rounded-xl border border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-900/50 dark:bg-indigo-950/30">
            <h2 class="text-base font-bold text-indigo-950 dark:text-indigo-100">{{ __('showcase_bulk.result_title') }}</h2>
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6 text-center">
                @foreach ([
                    'requested' => __('showcase_bulk.stat_requested'),
                    'created' => __('showcase_bulk.stat_created'),
                    'with_photo' => __('showcase_bulk.stat_with_photo'),
                    'without_photo' => __('showcase_bulk.stat_without_photo'),
                    'skipped_no_photo' => __('showcase_bulk.stat_skipped_photo'),
                    'skipped_no_location' => __('showcase_bulk.stat_skipped_location'),
                ] as $key => $label)
                    <div class="rounded-lg border border-indigo-100 bg-white px-2 py-3 dark:border-indigo-900/40 dark:bg-gray-900/60">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-indigo-800/80 dark:text-indigo-200/80">{{ $label }}</dt>
                        <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{{ (int) ($summary[$key] ?? 0) }}</dd>
                    </div>
                @endforeach
            </dl>

            @if (is_array($groupedWarnings) && $groupedWarnings !== [])
                <div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/40">
                    <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">{{ __('showcase_bulk.photo_warnings_title') }}</p>
                    <p class="mt-1 text-xs text-amber-900/90 dark:text-amber-200/90">{{ __('showcase_bulk.photo_warnings_help') }}</p>
                    <ul class="mt-3 space-y-2 text-xs text-amber-950 dark:text-amber-100">
                        @foreach ($groupedWarnings as $w)
                            @if (is_array($w))
                                <li class="rounded border border-amber-200/80 bg-white/70 px-3 py-2 dark:border-amber-900/50 dark:bg-gray-900/50">
                                    <span class="font-semibold">
                                        {{ ! empty($w['skipped']) ? __('showcase_bulk.warning_skipped') : __('showcase_bulk.warning_without_photo') }} —
                                        {{ __('showcase_bulk.warning_count', ['count' => (int) ($w['count'] ?? 0)]) }}
                                    </span>
                                    <span class="text-amber-900/90 dark:text-amber-200/90"> · {{ $w['reason'] ?? '' }}</span>
                                    @if (! empty($w['category']))
                                        <span class="block mt-0.5">{{ $w['category'] }}</span>
                                    @endif
                                    @if (! empty($w['folder']))
                                        <span class="mt-0.5 block font-mono text-[11px] text-amber-900/80 dark:text-amber-200/80">uploads/matrimony_photos/{{ $w['folder'] }}/</span>
                                    @endif
                                    @if (! empty($w['profile_ids']) && is_array($w['profile_ids']))
                                        <span class="mt-1 block text-[11px]">{{ __('showcase_bulk.profile_ids') }}: {{ implode(', ', array_map(fn ($id) => '#'.$id, $w['profile_ids'])) }}</span>
                                    @endif
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('admin.showcase-profile.bulk-store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Number of profiles (1–50)</label>
            <input type="number" name="count" min="1" max="50" value="{{ old('count', '5') }}" required class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-24 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
            <select name="gender" class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-full max-w-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="random" {{ old('gender', 'random') === 'random' ? 'selected' : '' }}>Random</option>
                <option value="male" {{ old('gender') === 'male' ? 'selected' : '' }}>Male</option>
                <option value="female" {{ old('gender') === 'female' ? 'selected' : '' }}>Female</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Random = each profile gets random gender. Otherwise all use selected gender; other fields remain random per profile.</p>
        </div>

        <div class="rounded border border-gray-200 bg-gray-50 px-4 py-3 text-xs leading-relaxed text-gray-700 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
            <p class="font-semibold text-gray-900 dark:text-gray-100">Showcase image folders (strict)</p>
            <p class="mt-1 font-mono">uploads/matrimony_photos/eng/{gender}/{religion}/{marital_status}/{age_bucket}/</p>
            <p class="mt-1">No <code class="rounded bg-gray-200 px-1 dark:bg-gray-800">any</code> fallbacks. Upload photos in <a href="{{ route('admin.showcase-photo-pool.index') }}" class="text-indigo-600 dark:text-indigo-400 underline">Showcase photo pool</a>. Policy: <a href="{{ route('admin.auto-showcase-settings.edit') }}#bulk" class="text-indigo-600 dark:text-indigo-400 underline">Admin bulk settings</a>.</p>
        </div>

        <div class="rounded border border-violet-200 bg-violet-50/50 px-4 py-3 text-xs text-violet-950 dark:border-violet-900/50 dark:bg-violet-950/20 dark:text-violet-100">
            <p class="font-semibold">{{ __('showcase_bulk.policy_title') }}</p>
            <ul class="mt-2 space-y-1">
                <li><span class="font-medium">{{ __('showcase_bulk.policy_missing') }}:</span> {{ $policy['missing'] ?? '—' }}</li>
                <li><span class="font-medium">{{ __('showcase_bulk.policy_exhausted') }}:</span> {{ $policy['exhausted'] ?? '—' }}</li>
                <li><span class="font-medium">{{ __('showcase_bulk.policy_reuse') }}:</span> {{ $policy['reuse'] ?? '—' }}</li>
            </ul>
        </div>

        <p class="text-sm text-gray-500 dark:text-gray-400">All other mandatory fields are auto-filled with random values per profile. No manual input.</p>
        <div class="flex gap-3">
            <button type="submit" style="background-color: #059669; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Create</button>
            <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 text-sm">Cancel</a>
        </div>
    </form>
</div>

@php
    $created = $createdProfiles ?? collect();
    $recent = $recentShowcase ?? collect();
@endphp

@if ($created->isNotEmpty() || $recent->isNotEmpty())
    <div class="mt-6 bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div class="min-w-0">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100">Recent showcase profiles</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if (($bulkShowcaseLifecycle ?? 'draft') === 'draft')
                        Draft rows need <strong>Publish</strong> before they appear in member search. Active rows are already searchable (subject to filters).
                    @else
                        New bulk creates use <strong>active</strong> lifecycle; publish is only needed if you switch Admin bulk back to draft or change a profile to draft elsewhere.
                    @endif
                </p>
            </div>
        </div>

        @if ($created->isNotEmpty())
            <div class="mb-5 rounded border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 dark:border-indigo-900/40 dark:bg-indigo-950/30 dark:text-indigo-200">
                <strong>Just created:</strong> {{ $created->count() }} profile(s)
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-4">ID</th>
                        <th class="py-2 pr-4">Name</th>
                        <th class="py-2 pr-4">Lifecycle</th>
                        <th class="py-2 pr-4">Photo</th>
                        <th class="py-2 pr-4">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($created as $p)
                        @php $hasNoPhoto = isset($noPhotoIds[$p->id]); @endphp
                        <tr class="{{ $hasNoPhoto ? 'bg-amber-50/80 dark:bg-amber-950/20' : '' }}">
                            <td class="py-3 pr-4 font-semibold text-gray-900 dark:text-gray-100">#{{ $p->id }}</td>
                            <td class="py-3 pr-4 text-gray-800 dark:text-gray-200">{{ $p->full_name ?? ('Profile #' . $p->id) }}</td>
                            <td class="py-3 pr-4"><span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ $p->lifecycle_state ?? '—' }}</span></td>
                            <td class="py-3 pr-4">
                                @if ($hasNoPhoto || trim((string) ($p->profile_photo ?? '')) === '')
                                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-100">{{ __('showcase_bulk.table_no_photo') }}</span>
                                @else
                                    <div class="flex items-center gap-2">
                                        <img src="{{ $p->profile_photo_url }}" alt="" class="h-10 w-10 rounded-full object-cover border border-gray-200 dark:border-gray-700" />
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('showcase_bulk.table_has_photo') }}</span>
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1, 'profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-gray-100 hover:bg-gray-200 text-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800 dark:text-gray-100">Edit all</a>
                                    <a href="{{ route('matrimony.profile.upload-photo', ['profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Photos</a>
                                    @if (($p->lifecycle_state ?? null) !== 'active')
                                        <form method="POST" action="{{ route('admin.showcase-profile.publish', $p->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white">Publish</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.showcase-profile.delete', $p->id) }}" onsubmit="return confirm('Delete this showcase profile?');">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    @foreach ($recent as $p)
                        @if ($created->pluck('id')->contains($p->id))
                            @continue
                        @endif
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-gray-900 dark:text-gray-100">#{{ $p->id }}</td>
                            <td class="py-3 pr-4 text-gray-800 dark:text-gray-200">{{ $p->full_name ?? ('Profile #' . $p->id) }}</td>
                            <td class="py-3 pr-4"><span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ $p->lifecycle_state ?? '—' }}</span></td>
                            <td class="py-3 pr-4">
                                @if (trim((string) ($p->profile_photo ?? '')) === '')
                                    <span class="text-xs text-gray-400">—</span>
                                @else
                                    <img src="{{ $p->profile_photo_url }}" alt="" class="h-10 w-10 rounded-full object-cover border border-gray-200 dark:border-gray-700" />
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'full', 'all' => 1, 'profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-gray-100 hover:bg-gray-200 text-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800 dark:text-gray-100">Edit all</a>
                                    <a href="{{ route('matrimony.profile.upload-photo', ['profile_id' => $p->id]) }}" class="px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Photos</a>
                                    @if (($p->lifecycle_state ?? null) !== 'active')
                                        <form method="POST" action="{{ route('admin.showcase-profile.publish', $p->id) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white">Publish</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.showcase-profile.delete', $p->id) }}" onsubmit="return confirm('Delete this showcase profile?');">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
