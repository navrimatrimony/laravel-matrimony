@extends('layouts.admin-showcase')

@section('showcase_content')
@php
    $lbl = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
@endphp
<div class="space-y-6">
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-1">{{ __('showcase_photo_pool_admin.title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('showcase_photo_pool_admin.intro') }}</p>
        <p class="font-mono text-xs text-gray-600 dark:text-gray-400">uploads/matrimony_photos/eng/{gender}/{religion}/{marital_status}/{age_bucket}/</p>
        <div class="mt-4 flex flex-wrap gap-2 text-sm">
            <a href="{{ route('admin.showcase-profile.bulk-create') }}" class="inline-flex rounded-lg bg-violet-50 px-3 py-1.5 font-medium text-violet-800 ring-1 ring-violet-200 hover:bg-violet-100 dark:bg-violet-900/30 dark:text-violet-200 dark:ring-violet-700">{{ __('showcase_photo_pool_admin.link_bulk') }}</a>
            <a href="{{ route('admin.auto-showcase-settings.edit') }}#bulk" class="inline-flex rounded-lg bg-gray-50 px-3 py-1.5 font-medium text-gray-800 ring-1 ring-gray-200 hover:bg-gray-100 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-700">{{ __('showcase_photo_pool_admin.link_policy') }}</a>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if ($errors->any())
        <ul class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-200 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('showcase_photo_pool_admin.upload_title') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('showcase_photo_pool_admin.upload_help') }}</p>
            <form method="POST" action="{{ route('admin.showcase-photo-pool.store') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label class="{{ $lbl }}">{{ __('showcase_photo_pool_admin.field_gender') }}</label>
                    <select name="gender" required class="mt-1 w-full max-w-xs rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">—</option>
                        @foreach ($genders as $g)
                            <option value="{{ $g }}" @selected(old('gender', $filterGender) === $g)>{{ ucfirst($g) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('showcase_photo_pool_admin.field_religion') }}</label>
                    <select name="religion_id" required class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">—</option>
                        @foreach ($religions as $r)
                            <option value="{{ $r->id }}" @selected((int) old('religion_id', $filterReligionId) === (int) $r->id)>{{ $r->label_en ?? $r->label }} ({{ $r->key }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('showcase_photo_pool_admin.field_marital') }}</label>
                    <select name="marital_status_id" required class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">—</option>
                        @foreach ($maritalStatuses as $m)
                            <option value="{{ $m->id }}" @selected((int) old('marital_status_id', $filterMaritalId) === (int) $m->id)>{{ $m->label }} ({{ $m->key }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('showcase_photo_pool_admin.field_age') }}</label>
                    <select name="age_bucket" required class="mt-1 w-full max-w-xs rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">—</option>
                        @foreach ($ageBuckets as $bucket)
                            <option value="{{ $bucket }}" @selected(old('age_bucket', $filterAgeBucket) === $bucket)>{{ $bucket }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('showcase_photo_pool_admin.field_files') }}</label>
                    <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required class="mt-1 block w-full text-sm text-gray-700 dark:text-gray-300">
                </div>
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">{{ __('showcase_photo_pool_admin.upload_btn') }}</button>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('showcase_photo_pool_admin.browse_title') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('showcase_photo_pool_admin.browse_help') }}</p>
            <form method="GET" action="{{ route('admin.showcase-photo-pool.index') }}" class="mt-4 space-y-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <select name="gender" class="rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">{{ __('showcase_photo_pool_admin.any_gender') }}</option>
                        @foreach ($genders as $g)
                            <option value="{{ $g }}" @selected($filterGender === $g)>{{ ucfirst($g) }}</option>
                        @endforeach
                    </select>
                    <select name="age_bucket" class="rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">{{ __('showcase_photo_pool_admin.any_age') }}</option>
                        @foreach ($ageBuckets as $bucket)
                            <option value="{{ $bucket }}" @selected($filterAgeBucket === $bucket)>{{ $bucket }}</option>
                        @endforeach
                    </select>
                </div>
                <select name="religion_id" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">{{ __('showcase_photo_pool_admin.any_religion') }}</option>
                    @foreach ($religions as $r)
                        <option value="{{ $r->id }}" @selected($filterReligionId === (int) $r->id)>{{ $r->label_en ?? $r->label }}</option>
                    @endforeach
                </select>
                <select name="marital_status_id" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">{{ __('showcase_photo_pool_admin.any_marital') }}</option>
                    @foreach ($maritalStatuses as $m)
                        <option value="{{ $m->id }}" @selected($filterMaritalId === (int) $m->id)>{{ $m->label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('showcase_photo_pool_admin.browse_btn') }}</button>
            </form>

            @if ($browseFolder)
                <p class="mt-4 font-mono text-[11px] text-gray-600 dark:text-gray-400">uploads/matrimony_photos/{{ $browseFolder }}/</p>
                @if ($browsePhotos === [])
                    <p class="mt-3 text-sm text-amber-800 dark:text-amber-200">{{ __('showcase_photo_pool_admin.browse_empty') }}</p>
                @else
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($browsePhotos as $photo)
                            <div class="rounded-lg border border-gray-200 p-2 dark:border-gray-700 {{ ! empty($photo['is_used']) ? 'ring-2 ring-amber-300 dark:ring-amber-700' : '' }}">
                                <img src="{{ $photo['url'] }}" alt="" class="h-24 w-full rounded object-cover">
                                <p class="mt-1 truncate text-[10px] text-gray-600 dark:text-gray-400" title="{{ $photo['filename'] }}">{{ $photo['filename'] }}</p>
                                @if (! empty($photo['is_used']))
                                    <span class="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-100">{{ __('showcase_photo_pool_admin.badge_used') }}</span>
                                @else
                                    <span class="mt-1 inline-block rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-100">{{ __('showcase_photo_pool_admin.badge_unused') }}</span>
                                @endif
                                <form method="POST" action="{{ route('admin.showcase-photo-pool.destroy') }}" class="mt-2" onsubmit="return confirm(@json(__('showcase_photo_pool_admin.delete_confirm')));">
                                    @csrf
                                    <input type="hidden" name="relative_path" value="{{ $photo['relative_path'] }}">
                                    <input type="hidden" name="gender" value="{{ $filterGender }}">
                                    <input type="hidden" name="religion_id" value="{{ $filterReligionId }}">
                                    <input type="hidden" name="marital_status_id" value="{{ $filterMaritalId }}">
                                    <input type="hidden" name="age_bucket" value="{{ $filterAgeBucket }}">
                                    <button type="submit" class="w-full rounded bg-red-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-red-700">{{ __('showcase_photo_pool_admin.delete_btn') }}</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <div class="flex flex-wrap items-end justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('showcase_photo_pool_admin.matrix_title') }}</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('showcase_photo_pool_admin.matrix_help') }}</p>
            </div>
            <dl class="flex flex-wrap gap-4 text-center text-sm">
                <div><dt class="text-xs text-gray-500">{{ __('showcase_photo_pool_admin.matrix_buckets') }}</dt><dd class="font-bold text-gray-900 dark:text-gray-100">{{ $matrixBucketCount }}</dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('showcase_photo_pool_admin.matrix_photos') }}</dt><dd class="font-bold text-gray-900 dark:text-gray-100">{{ $matrixTotalPhotos }}</dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('showcase_photo_pool_admin.matrix_exhausted') }}</dt><dd class="font-bold text-amber-700 dark:text-amber-300">{{ $matrixExhaustedBuckets }}</dd></div>
            </dl>
        </div>
        @if ($matrix === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('showcase_photo_pool_admin.matrix_empty') }}</p>
        @else
            <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900">
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-3">{{ __('showcase_photo_pool_admin.col_gender') }}</th>
                            <th class="py-2 pr-3">{{ __('showcase_photo_pool_admin.col_religion') }}</th>
                            <th class="py-2 pr-3">{{ __('showcase_photo_pool_admin.col_marital') }}</th>
                            <th class="py-2 pr-3">{{ __('showcase_photo_pool_admin.col_age') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('showcase_photo_pool_admin.col_total') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('showcase_photo_pool_admin.col_unused') }}</th>
                            <th class="py-2 pr-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($matrix as $row)
                            @php
                                $low = (int) ($row['unused'] ?? 0) < 2;
                                $exhausted = (int) ($row['total'] ?? 0) > 0 && (int) ($row['unused'] ?? 0) === 0;
                            @endphp
                            <tr class="{{ $exhausted ? 'bg-amber-50/60 dark:bg-amber-950/20' : ($low ? 'bg-orange-50/40 dark:bg-orange-950/10' : '') }}">
                                <td class="py-2 pr-3 capitalize">{{ $row['gender'] }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">{{ $row['religion_key'] }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">{{ $row['marital_key'] }}</td>
                                <td class="py-2 pr-3">{{ $row['age_bucket'] }}</td>
                                <td class="py-2 pr-3 text-right font-semibold">{{ $row['total'] }}</td>
                                <td class="py-2 pr-3 text-right {{ (int) ($row['unused'] ?? 0) === 0 ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ $row['unused'] }}</td>
                                <td class="py-2 pr-3 text-right">
                                    @if (! empty($row['religion_id']) && ! empty($row['marital_status_id']))
                                        <a href="{{ route('admin.showcase-photo-pool.index', ['gender' => $row['gender'], 'religion_id' => $row['religion_id'], 'marital_status_id' => $row['marital_status_id'], 'age_bucket' => $row['age_bucket']]) }}" class="text-indigo-600 hover:underline dark:text-indigo-400 text-xs">{{ __('showcase_photo_pool_admin.matrix_view') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
