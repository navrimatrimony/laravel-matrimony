@php
    /** @var array<string, mixed> $ocrJudgeParticipation */
    $ocrJudgeParticipation = is_array($ocrJudgeParticipation ?? null) ? $ocrJudgeParticipation : [];
    $participated = (bool) ($ocrJudgeParticipation['participated'] ?? false);
    $judgedFields = is_array($ocrJudgeParticipation['judged_fields'] ?? null) ? $ocrJudgeParticipation['judged_fields'] : [];
    $attemptEngines = is_array($ocrJudgeParticipation['attempt_engines'] ?? null) ? $ocrJudgeParticipation['attempt_engines'] : [];
    $summary = (string) ($ocrJudgeParticipation['summary'] ?? 'Judge participation unknown.');
@endphp

<section class="rounded-xl border border-violet-200 bg-violet-50/40 p-4 shadow-sm"
         data-testid="ocr-judge-participation"
         data-participated="{{ $participated ? '1' : '0' }}">
    <h3 class="text-sm font-semibold text-violet-950">Judge participation</h3>
    <p class="mt-1 text-xs text-violet-900/80">
        Sarvam judge runs only on Blueprint triggers (name conflict / DOB missing / mobile missing / religion missing).
        Gender missing does not trigger Judge.
    </p>
    <p class="mt-2 text-sm font-medium text-violet-950" data-testid="ocr-judge-participation-summary">
        {{ $summary }}
    </p>

    @if ($attemptEngines !== [])
        <p class="mt-2 text-xs text-violet-900" data-testid="ocr-judge-attempt-engines">
            Sarvam attempt engine(s): {{ implode(', ', $attemptEngines) }}
        </p>
    @endif

    @if ($judgedFields !== [])
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-violet-100 text-left text-sm"
                   data-testid="ocr-judge-fields-table">
                <thead class="bg-violet-100/60 text-xs font-semibold uppercase tracking-wide text-violet-800">
                    <tr>
                        <th class="px-3 py-2">Field</th>
                        <th class="px-3 py-2">Final after Judge</th>
                        <th class="px-3 py-2">Source</th>
                        <th class="px-3 py-2">Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-violet-100 bg-white text-gray-900">
                    @foreach ($judgedFields as $index => $field)
                        <tr data-testid="ocr-judge-field-row-{{ $index }}" data-field-key="{{ $field['field_key'] ?? '' }}">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $field['field_label'] ?? ($field['field_key'] ?? '—') }}</div>
                                <div class="text-[11px] text-gray-500">{{ $field['field_key'] ?? '' }}</div>
                            </td>
                            <td class="px-3 py-2">{{ $field['final'] !== null && $field['final'] !== '' ? $field['final'] : '—' }}</td>
                            <td class="px-3 py-2">{{ $field['source'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $field['reason'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
