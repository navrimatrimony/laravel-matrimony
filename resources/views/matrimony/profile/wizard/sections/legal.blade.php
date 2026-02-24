{{-- Phase-5 SSOT: Legal cases (repeatable) --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Legal cases</h2>
    @php $legalRows = old('legal_cases', $profile_legal_cases ?? collect()); @endphp
    @foreach($legalRows as $idx => $row)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded">
            <input type="hidden" name="legal_cases[{{ $idx }}][id]" value="{{ is_object($row) ? $row->id : ($row['id'] ?? '') }}">
            <div class="flex flex-col gap-1">
                <label class="text-sm text-gray-600 dark:text-gray-400">Case type</label>
                <select name="legal_cases[{{ $idx }}][legal_case_type_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">— Select —</option>
                    @foreach($legalCaseTypes ?? [] as $item)
                        <option value="{{ $item->id }}" {{ (string)(is_object($row) ? ($row->legal_case_type_id ?? '') : ($row['legal_case_type_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                    @endforeach
                </select>
            </div>
            <input type="text" name="legal_cases[{{ $idx }}][court_name]" value="{{ is_object($row) ? $row->court_name : ($row['court_name'] ?? '') }}" placeholder="Court" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <input type="text" name="legal_cases[{{ $idx }}][case_number]" value="{{ is_object($row) ? $row->case_number : ($row['case_number'] ?? '') }}" placeholder="Case Number" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <input type="text" name="legal_cases[{{ $idx }}][case_stage]" value="{{ is_object($row) ? $row->case_stage : ($row['case_stage'] ?? '') }}" placeholder="Stage" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            @php
                $rawNext = is_object($row) ? ($row->next_hearing_date ?? '') : ($row['next_hearing_date'] ?? '');
                $nextDate = '';
                if ($rawNext !== '' && $rawNext !== null) {
                    try {
                        $nextDate = \Carbon\Carbon::parse($rawNext)->format('Y-m-d');
                    } catch (\Throwable $e) {
                        $nextDate = is_string($rawNext) ? substr($rawNext, 0, 10) : '';
                    }
                }
            @endphp
            <input type="date" name="legal_cases[{{ $idx }}][next_hearing_date]" value="{{ $nextDate }}" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <label class="flex items-center gap-2"><input type="checkbox" name="legal_cases[{{ $idx }}][active_status]" value="1" {{ (is_object($row) ? $row->active_status : ($row['active_status'] ?? true)) ? 'checked' : '' }}> Active</label>
            <div class="md:col-span-2"><textarea name="legal_cases[{{ $idx }}][notes]" rows="1" placeholder="Notes" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ is_object($row) ? $row->notes : ($row['notes'] ?? '') }}</textarea></div>
        </div>
    @endforeach
</div>
