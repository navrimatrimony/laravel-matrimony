@php
    $actionsDialogId = 'bulk-actions-panel-'.(int) $item->id;
    $dupPanelDialogId = 'bulk-dup-panel-'.(int) $item->id;
    $waPanelDialogId = 'bulk-wa-panel-'.(int) $item->id;
    $actionsCandidateName = trim((string) ($candidate['full_name'] ?? $item->original_filename ?? 'Candidate'));
    $primaryAction = match (true) {
        $canSendWhatsAppPermission => [
            'kind' => 'wa',
            'label' => 'WhatsApp पाठवा',
            'testid' => 'bulk-primary-send-whatsapp',
        ],
        in_array('missing_mobile', $pipelineReasonCodes ?? [], true) && $intake => [
            'kind' => 'link',
            'label' => 'मोबाईल भरा',
            'url' => route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]),
            'testid' => 'bulk-primary-fill-mobile',
        ],
        in_array('override_missing_requirements', $pipelineReasonCodes ?? [], true) && $intake => [
            'kind' => 'link',
            'label' => 'मोबाईल भरा',
            'url' => route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]),
            'testid' => 'bulk-primary-fill-mobile',
        ],
        ($duplicateVerification['has_hints'] ?? false) && $duplicateHints !== [] => [
            'kind' => 'dup',
            'label' => 'Duplicate पहा',
            'testid' => 'bulk-primary-verify-duplicate',
        ],
        $canAddManualTranscript ?? false => [
            'kind' => 'link',
            'label' => 'Manual transcript',
            'url' => route('admin.bulk-intakes.items.manual-transcript', [$batch, $item]),
            'testid' => 'bulk-primary-manual-transcript',
        ],
        $intake => [
            'kind' => 'link',
            'label' => 'Correct candidate',
            'url' => route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]),
            'testid' => 'bulk-primary-correct-candidate',
        ],
        default => [
            'kind' => 'more',
            'label' => 'कृती',
            'testid' => 'bulk-primary-more-actions',
        ],
    };
@endphp

@if (($primaryAction['kind'] ?? '') === 'link')
    <a
        href="{{ $primaryAction['url'] }}"
        data-testid="{{ $primaryAction['testid'] }}"
        class="rounded-md border border-emerald-300 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-900 hover:bg-emerald-100"
    >{{ $primaryAction['label'] }}</a>
@elseif (($primaryAction['kind'] ?? '') === 'wa')
    <button
        type="button"
        data-bulk-wa-open="{{ $waPanelDialogId }}"
        data-testid="{{ $primaryAction['testid'] }}"
        class="rounded-md border border-emerald-300 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-900 hover:bg-emerald-100"
    >{{ $primaryAction['label'] }}</button>
@elseif (($primaryAction['kind'] ?? '') === 'dup')
    <button
        type="button"
        data-bulk-dup-open="{{ $dupPanelDialogId }}"
        data-testid="{{ $primaryAction['testid'] }}"
        class="rounded-md border border-purple-300 bg-purple-50 px-2 py-1 text-[11px] font-semibold text-purple-900 hover:bg-purple-100"
    >{{ $primaryAction['label'] }}</button>
@endif

<button
    type="button"
    data-bulk-actions-open="{{ $actionsDialogId }}"
    data-testid="bulk-open-actions-panel"
    class="rounded-md border border-gray-200 bg-white px-2 py-1 text-[11px] font-medium text-gray-700 hover:bg-gray-50"
>अधिक कृती</button>

<dialog id="{{ $actionsDialogId }}" class="w-[min(520px,95vw)] max-h-[90vh] rounded-xl border border-gray-200 bg-white p-0 shadow-xl backdrop:bg-black/40">
    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">Item actions</h3>
            <p class="text-xs text-gray-500">{{ $actionsCandidateName }} · Seq {{ (int) $item->item_sequence }}</p>
        </div>
        <button type="button" data-bulk-actions-close class="rounded-md px-2 py-1 text-lg leading-none text-gray-500 hover:bg-gray-100" aria-label="Close">×</button>
    </div>
    <div class="max-h-[calc(90vh-4.5rem)] space-y-2 overflow-y-auto p-4 text-sm">
        @if ($intake)
            <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="block font-medium text-indigo-600 hover:text-indigo-800">Open intake review</a>
            <a href="{{ route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]) }}" class="block font-medium text-emerald-700 hover:text-emerald-900">Correct candidate</a>
        @endif
        @if ($canAddManualTranscript)
            <a href="{{ route('admin.bulk-intakes.items.manual-transcript', [$batch, $item]) }}" class="block font-medium text-orange-700 hover:text-orange-900">Add manual transcript (OCR failed fallback)</a>
        @endif

        @if ($intake && $intake->parse_status === 'pending' && $item->item_status !== \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED && ! $intake->approved_by_user && ! $intake->intake_locked)
            <form method="POST" action="{{ route('admin.bulk-intakes.items.queue-free-parse', [$batch, $item]) }}">
                @csrf
                <button type="submit" class="text-left font-medium text-indigo-600 hover:text-indigo-800">Queue free parse item</button>
            </form>
        @endif

        @if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-needs-review', [$batch, $item]) }}">
                @csrf
                <button type="submit" class="text-left font-medium text-green-700 hover:text-green-900">Clear needs review</button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]) }}">
                @csrf
                <button type="submit" class="text-left font-medium text-amber-700 hover:text-amber-900">Mark needs review</button>
            </form>
        @endif

        @if (($duplicateVerification['has_hints'] ?? false) && $duplicateHints !== [])
            <button
                type="button"
                data-bulk-dup-open="{{ $dupPanelDialogId }}"
                data-testid="bulk-open-duplicate-verify-panel"
                class="text-left text-sm font-medium text-purple-700 hover:text-purple-900"
            >🔍 Verify duplicate{{ count($duplicateHints) > 1 ? ' ('.count($duplicateHints).')' : '' }}</button>
        @endif

        @if ($manualDuplicateActive)
            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-duplicate', [$batch, $item]) }}">
                @csrf
                <button type="submit" class="text-left font-medium text-rose-700 hover:text-rose-900">Clear duplicate</button>
            </form>
        @elseif ($duplicateHints !== [])
            <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]) }}">
                @csrf
                @if (! empty($primaryDuplicateHint['matched_intake_id']))
                    <input type="hidden" name="matched_biodata_intake_id" value="{{ (int) $primaryDuplicateHint['matched_intake_id'] }}">
                @endif
                @if (! empty($primaryDuplicateHint['matched_profile_id']))
                    <input type="hidden" name="matched_profile_id" value="{{ (int) $primaryDuplicateHint['matched_profile_id'] }}">
                @endif
                <input type="hidden" name="reason" value="{{ trim('Duplicate/history hint: '.(string) ($primaryDuplicateHint['label'] ?? 'Possible duplicate')) }}">
                <button type="submit" class="text-left font-medium text-rose-700 hover:text-rose-900">Mark duplicate</button>
            </form>
        @endif

        @if ($duplicateAutoBlocked && ! $duplicateOverrideActive && ! $manualDuplicateActive)
            <form method="POST" action="{{ route('admin.bulk-intakes.items.override-duplicate-block', [$batch, $item]) }}">
                @csrf
                <input type="hidden" name="reason" value="Admin override — proceed despite auto block">
                <button type="submit" data-testid="bulk-override-duplicate-block" class="text-left font-medium text-sky-700 hover:text-sky-900">Override — proceed</button>
            </form>
        @elseif ($duplicateOverrideActive)
            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-duplicate-block-override', [$batch, $item]) }}">
                @csrf
                <button type="submit" data-testid="bulk-clear-duplicate-override" class="text-left font-medium text-sky-700 hover:text-sky-900">Clear override</button>
            </form>
        @endif

        @if ($manualScreeningActive)
            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-screening-review', [$batch, $item]) }}">
                @csrf
                <button type="submit" class="text-left font-medium text-indigo-700 hover:text-indigo-900">Clear override</button>
            </form>
        @endif

        @if ($canSendWhatsAppPermission || $whatsappConsentStatus !== '' || $consentReceived)
            @include('admin.bulk-intakes.partials.item-whatsapp-registration-panel')
        @endif

        @if ($intake)
            <div class="mt-2 border-t border-gray-100 pt-2">
                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-400">Record history</span>
                @foreach ([
                    'already_married' => ['label' => 'Already married', 'testid' => 'bulk-mark-already-married'],
                    'not_interested' => ['label' => 'Not interested', 'testid' => 'bulk-mark-not-interested'],
                    'wrong_number' => ['label' => 'Wrong number', 'testid' => 'bulk-mark-wrong-number'],
                ] as $historyReasonKey => $historyAction)
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]) }}" class="mt-1">
                        @csrf
                        <input type="hidden" name="status" value="stopped">
                        <input type="hidden" name="reason_key" value="{{ $historyReasonKey }}">
                        <button
                            type="submit"
                            data-testid="{{ $historyAction['testid'] }}"
                            class="text-left font-medium text-red-700 hover:text-red-900"
                        >{{ $historyAction['label'] }}</button>
                    </form>
                @endforeach
            </div>
        @endif
    </div>
</dialog>

@if (($duplicateVerification['has_hints'] ?? false) && $duplicateHints !== [])
    @include('admin.bulk-intakes.partials.item-duplicate-verify-panel')
@endif