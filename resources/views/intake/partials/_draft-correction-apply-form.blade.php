@php
    $applyField = (string) ($applyField ?? '');
    $applyValue = (string) ($applyValue ?? '');
    $applyRoute = (string) ($draftCorrectionApplyRoute ?? '');
    $applyReason = (string) ($applyReason ?? 'draft_not_in_parsed');
    $applyReasonLabel = match ($applyReason) {
        'detected_not_included' => __('intake.normalized_draft_apply_badge_detected_not_included'),
        'value_mismatch' => __('intake.normalized_draft_apply_badge_value_mismatch'),
        default => __('intake.normalized_draft_apply_badge_draft_not_in_parsed'),
    };
@endphp
@if (! empty($draftCorrectionApplyEnabled) && $applyRoute !== '' && $applyField !== '' && $applyValue !== '')
    <div class="space-y-1.5" data-draft-apply>
        <span class="inline-flex items-center rounded-full border border-emerald-600 bg-emerald-100 dark:bg-emerald-900/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900 dark:text-emerald-100">
            {{ $applyReasonLabel }}
        </span>
        <div class="flex flex-wrap items-center gap-2">
            <span data-apply-display class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap break-words">{{ $applyValue }}</span>
            <input
                type="text"
                data-apply-input
                value="{{ $applyValue }}"
                class="hidden min-w-[12rem] max-w-full rounded border border-emerald-400 bg-white px-2 py-1 text-xs text-gray-900 dark:border-emerald-600 dark:bg-gray-900 dark:text-gray-100"
            >
            <button
                type="button"
                data-apply-edit
                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                {{ __('intake.normalized_draft_apply_edit_button') }}
            </button>
            <button
                type="button"
                data-apply-done
                class="hidden inline-flex items-center rounded-md border border-emerald-500 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900 hover:bg-emerald-100 dark:border-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-100"
            >
                {{ __('intake.normalized_draft_apply_edit_done') }}
            </button>
            <form method="POST" action="{{ $applyRoute }}" class="inline" onsubmit="return confirm(@json(__('intake.normalized_draft_apply_confirm')));">
                @csrf
                <input type="hidden" name="field" value="{{ $applyField }}">
                <input type="hidden" name="value" data-apply-hidden value="{{ $applyValue }}">
                <button type="submit" class="inline-flex items-center rounded-md border border-emerald-600 bg-emerald-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white hover:bg-emerald-700">
                    {{ __('intake.normalized_draft_apply_button') }}
                </button>
            </form>
        </div>
    </div>
@endif
