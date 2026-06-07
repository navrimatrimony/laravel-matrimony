@php
    $applyField = (string) ($applyField ?? '');
    $applyValue = (string) ($applyValue ?? '');
    $applyRoute = (string) ($draftCorrectionApplyRoute ?? '');
@endphp
@if (! empty($draftCorrectionApplyEnabled) && $applyRoute !== '' && $applyField !== '' && $applyValue !== '')
    <form method="POST" action="{{ $applyRoute }}" class="inline" onsubmit="return confirm(@json(__('intake.normalized_draft_apply_confirm')));">
        @csrf
        <input type="hidden" name="field" value="{{ $applyField }}">
        <input type="hidden" name="value" value="{{ $applyValue }}">
        <button type="submit" class="inline-flex items-center rounded-md border border-emerald-600 bg-emerald-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white hover:bg-emerald-700">
            {{ __('intake.normalized_draft_apply_button') }}
        </button>
    </form>
@endif
