@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">OCR Patterns (Day-30)</h1>

    @if (session('success'))
        <div class="mb-4 px-4 py-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 text-green-800 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-6 p-4 border rounded-lg bg-gray-50 dark:bg-gray-700/40">
        <h2 class="text-lg font-semibold mb-3 text-gray-800 dark:text-gray-100">Filters</h2>
        <form method="GET" action="{{ route('admin.ocr-patterns.index') }}" class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">Source</label>
                <select name="source" class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    <option value="">All</option>
                    <option value="frequency_rule" {{ request('source') === 'frequency_rule' ? 'selected' : '' }}>frequency_rule</option>
                    <option value="ai_generalized" {{ request('source') === 'ai_generalized' ? 'selected' : '' }}>ai_generalized</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">Active</label>
                <select name="is_active" class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    <option value="">All</option>
                    <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>Yes</option>
                    <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>No</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">Field key (search)</label>
                <input type="text" name="field_key" value="{{ request('field_key') }}" placeholder="e.g. blood_group"
                    class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">Usage count Ã¢â€°Â¥</label>
                <input type="number" name="usage_count_min" value="{{ request('usage_count_min') }}" min="0" step="1" placeholder="0"
                    class="border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 w-24">
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md">Apply</button>
            <a href="{{ route('admin.ocr-patterns.index') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300">Reset</a>
        </form>
    </div>

    <div class="w-full overflow-x-auto">
        <table class="w-full max-w-full border border-gray-200 dark:border-gray-700 table-fixed" style="table-layout: fixed;">
            <colgroup>
                <col style="width: 8%;">
                <col style="width: 18%;">
                <col style="width: 18%;">
                <col style="width: 5%;">
                <col style="width: 5%;">
                <col style="width: 10%;">
                <col style="width: 7%;">
                <col style="width: 8%;">
                <col style="width: 8%;">
                <col style="width: 13%;">
            </colgroup>
            <thead class="bg-gray-100 dark:bg-gray-700">
                <tr>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">field_key</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium truncate" title="wrong_pattern">wrong_pattern</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium truncate" title="corrected_value">corrected_value</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">use#</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">conf</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">source</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">is_active</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">family / ver</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">supersedes</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">authored</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">retirement</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">created</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium">updated</th>
                    <th class="text-left px-2 py-1.5 border-b text-xs font-medium whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($patterns as $pattern)
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <td class="px-2 py-1.5 text-gray-900 dark:text-gray-100 text-sm truncate" title="{{ $pattern->field_key }}">{{ $pattern->field_key }}</td>
                        <td class="px-2 py-1.5 text-gray-900 dark:text-gray-100 text-sm truncate" title="{{ $pattern->wrong_pattern }}">{{ Str::limit($pattern->wrong_pattern, 22) }}</td>
                        <td class="px-2 py-1.5 text-gray-900 dark:text-gray-100 text-sm truncate" title="{{ $pattern->corrected_value }}">{{ Str::limit($pattern->corrected_value, 22) }}</td>
                        <td class="px-2 py-1.5 text-gray-900 dark:text-gray-100 text-sm">{{ $pattern->usage_count }}</td>
                        <td class="px-2 py-1.5 text-gray-900 dark:text-gray-100 text-sm">{{ $pattern->pattern_confidence }}</td>
                        <td class="px-2 py-1.5 text-gray-900 dark:text-gray-100 text-sm truncate">{{ $pattern->source }}</td>
                        <td class="px-2 py-1.5">
                            @if ($pattern->is_active)
                                <span class="inline-block px-1.5 py-0.5 text-xs rounded bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">Active</span>
                            @else
                                <span class="inline-block px-1.5 py-0.5 text-xs rounded bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Inactive</span>
                            @endif
                        </td>
                        <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 text-xs truncate" title="{{ $pattern->rule_family_key ?? '—' }}">{{ $pattern->rule_family_key ? Str::limit($pattern->rule_family_key, 12) . ($pattern->rule_version ? ' v' . $pattern->rule_version : '') : '—' }}</td>
                        <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 text-xs">@if($pattern->supersedes_pattern_id)<a href="{{ route('admin.ocr-patterns.index', ['field_key' => '']) }}" class="underline" title="Pattern #{{ $pattern->supersedes_pattern_id }}">#{{ $pattern->supersedes_pattern_id }}</a>@else—@endif</td>
                        <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 text-xs truncate">{{ $pattern->authored_by_type ?? '—' }}</td>
                        <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 text-xs truncate" title="{{ $pattern->retirement_reason ?? '' }}">{{ $pattern->retired_at ? ($pattern->retirement_reason ? Str::limit($pattern->retirement_reason, 14) : (optional($pattern->retired_at)->format('m/d') ?? '—')) : '—' }}</td>
                        <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 text-xs truncate" title="{{ $pattern->created_at?->format('Y-m-d H:i:s') }}">{{ $pattern->created_at?->format('m/d H:i') }}</td>
                        <td class="px-2 py-1.5 text-gray-600 dark:text-gray-400 text-xs truncate" title="{{ $pattern->updated_at?->format('Y-m-d H:i:s') }}">{{ $pattern->updated_at?->format('m/d H:i') }}</td>
                        <td class="px-2 py-1.5 whitespace-nowrap">
                            <form method="POST" action="{{ route('admin.ocr-patterns.toggle-active', $pattern) }}" class="inline-block ocr-pattern-toggle-form">
                                @csrf
                                @if ($pattern->is_active)
                                    <button type="submit" class="ocr-pattern-toggle-btn inline-flex items-center justify-center whitespace-nowrap min-w-[90px] px-2 py-1 text-xs font-semibold leading-5 !text-white bg-gray-900 hover:bg-black border border-gray-700 rounded disabled:opacity-70">Deactivate</button>
                                @else
                                    <button type="submit" class="ocr-pattern-toggle-btn inline-flex items-center justify-center whitespace-nowrap min-w-[90px] px-2 py-1 text-xs font-semibold leading-5 !text-white bg-gray-900 hover:bg-black border border-gray-700 rounded disabled:opacity-70">Activate</button>
                                @endif
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="14" class="px-4 py-4 text-gray-500 text-sm">No patterns found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <script>
    document.querySelectorAll('.ocr-pattern-toggle-form').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('.ocr-pattern-toggle-btn');
            if (btn && !btn.disabled) { btn.disabled = true; btn.textContent = '...'; }
        });
    });
    </script>

    @if ($patterns->hasPages())
        <div class="mt-4">
            {{ $patterns->links() }}
        </div>
    @endif
</div>
@endsection

