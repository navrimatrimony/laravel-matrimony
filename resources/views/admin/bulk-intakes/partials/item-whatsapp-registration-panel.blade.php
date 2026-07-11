@php
    $panelDialogId = 'bulk-wa-panel-'.(int) $item->id;
    $panelCandidateName = trim((string) ($candidate['full_name'] ?? $item->original_filename ?? 'Candidate'));
@endphp

<button
    type="button"
    data-bulk-wa-open="{{ $panelDialogId }}"
    data-testid="bulk-open-whatsapp-registration-panel"
    class="text-left text-sm font-medium text-violet-700 hover:text-violet-900"
>📱 WhatsApp / Registration</button>

<dialog id="{{ $panelDialogId }}" class="w-[min(540px,95vw)] max-h-[90vh] rounded-xl border border-gray-200 bg-white p-0 shadow-xl backdrop:bg-black/40">
    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">WhatsApp & Registration</h3>
            <p class="text-xs text-gray-500">{{ $panelCandidateName }} · Item #{{ (int) $item->id }}</p>
        </div>
        <button type="button" data-bulk-wa-close class="rounded-md px-2 py-1 text-lg leading-none text-gray-500 hover:bg-gray-100" aria-label="Close">×</button>
    </div>
    <div class="max-h-[calc(90vh-4.5rem)] space-y-3 overflow-y-auto p-4 text-sm">
        @if ($canSendWhatsAppPermission)
            <form method="POST" action="{{ route('admin.bulk-intakes.items.send-whatsapp-permission', [$batch, $item]) }}">
                @csrf
                <button
                    type="submit"
                    data-testid="bulk-send-whatsapp-permission"
                    class="text-left font-medium text-emerald-700 hover:text-emerald-900"
                >Send permission</button>
            </form>
        @endif

        @if ($whatsappManualTestEnabled && ($canSendWhatsAppPermission || $whatsappConsentStatus === \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_PERMISSION_SENT) && $manualWhatsAppPreview)
            <div class="rounded-md border border-sky-100 bg-sky-50 p-3">
                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-sky-700">Consent WhatsApp test</span>
                <p data-testid="bulk-whatsapp-message-preview" class="max-h-48 overflow-y-auto whitespace-pre-wrap text-xs text-sky-900">{{ $manualWhatsAppPreview['share_text'] ?? '' }}</p>
                @if ($manualWhatsAppShareUrl !== '')
                    <a
                        href="{{ $manualWhatsAppShareUrl }}"
                        target="_blank"
                        rel="noopener"
                        data-testid="bulk-open-whatsapp-manual-test"
                        class="mt-2 inline-flex text-sm font-medium text-emerald-700 hover:text-emerald-900"
                    >Open on my WhatsApp</a>
                @endif
            </div>
        @endif

        @if ($canSimulateWhatsAppReply && is_array($manualWhatsAppPreview['buttons'] ?? null))
            <div class="border-t border-gray-100 pt-2">
                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500">Simulate consent reply</span>
                @foreach ($manualWhatsAppPreview['buttons'] as $simulateButton)
                    @php
                        $simulateReplyChoice = (string) ($simulateButton['id'] ?? '');
                        $simulateReplyLabel = trim(((string) ($simulateButton['emoji'] ?? '')).' '.((string) ($simulateButton['title'] ?? '')));
                        $simulateReplyTestId = match ($simulateReplyChoice) {
                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_YES => 'bulk-simulate-whatsapp-yes',
                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_NO => 'bulk-simulate-whatsapp-no',
                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_ALREADY_MARRIED => 'bulk-simulate-whatsapp-married',
                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_WRONG_NUMBER => 'bulk-simulate-whatsapp-wrong',
                            default => 'bulk-simulate-whatsapp-reply',
                        };
                    @endphp
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.simulate-whatsapp-consent-reply', [$batch, $item]) }}" class="mt-1">
                        @csrf
                        <input type="hidden" name="reply_choice" value="{{ $simulateReplyChoice }}">
                        <button
                            type="submit"
                            data-testid="{{ $simulateReplyTestId }}"
                            class="text-left font-medium text-indigo-700 hover:text-indigo-900"
                        >{{ $simulateReplyLabel }}</button>
                    </form>
                @endforeach
            </div>
        @endif

        @if ($consentReceived)
            <div class="rounded-md border border-violet-100 bg-violet-50 p-3">
                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-violet-700">Registration (Phase E)</span>
                @if (is_array($registrationSummary['fields'] ?? null))
                    <div data-testid="bulk-registration-summary-preview" class="space-y-0.5 text-xs text-violet-900">
                        @foreach ($registrationSummary['fields'] as $summaryField)
                            <div>{{ $summaryField['icon'] ?? '⚠' }} {{ $summaryField['label'] ?? '' }}: {{ $summaryField['value'] ?? '—' }}</div>
                        @endforeach
                    </div>
                @endif
                @if ($canSendRegistrationSummary)
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.send-registration-summary', [$batch, $item]) }}" class="mt-2">
                        @csrf
                        <button type="submit" data-testid="bulk-send-registration-summary" class="text-left font-medium text-violet-700 hover:text-violet-900">Send registration summary</button>
                    </form>
                @endif
                @if ($whatsappManualTestEnabled && $registrationManualPreview)
                    <div class="mt-2 rounded-md border border-sky-100 bg-sky-50 p-2">
                        <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-sky-700">Registration WhatsApp test</span>
                        @if ($registrationStatus === \App\Services\Intake\BulkIntakeRegistrationService::STATUS_SUMMARY_SENT && $registrationFlowStepLabel !== '')
                            <p class="mb-1 text-[10px] font-medium text-sky-800">Flow step: {{ $registrationFlowStepLabel }}</p>
                        @endif
                        <p data-testid="bulk-registration-whatsapp-message-preview" class="max-h-56 overflow-y-auto whitespace-pre-wrap text-xs text-sky-900">{{ $registrationManualPreview['share_text'] ?? '' }}</p>
                        @if ($registrationWhatsAppShareUrl !== '')
                            <a
                                href="{{ $registrationWhatsAppShareUrl }}"
                                target="_blank"
                                rel="noopener"
                                data-testid="bulk-open-registration-whatsapp-test"
                                class="mt-2 inline-flex text-sm font-medium text-emerald-700 hover:text-emerald-900"
                            >Open summary on WhatsApp</a>
                        @endif
                    </div>
                @endif
                @if ($canSimulateRegistrationReply && $registrationNeedsFieldValueText)
                    <div class="mt-2 border-t border-violet-100 pt-2">
                        <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-violet-700">Simulate corrected value</span>
                        <form method="POST" action="{{ route('admin.bulk-intakes.items.simulate-registration-reply', [$batch, $item]) }}" class="mt-1 space-y-2">
                            @csrf
                            <input
                                type="text"
                                name="reply_text"
                                data-testid="bulk-simulate-registration-field-value"
                                class="w-full rounded-md border border-violet-200 px-2 py-1 text-sm"
                                placeholder="योग्य माहिती लिहा (उदा. Pune)"
                                maxlength="500"
                                required
                            >
                            <button type="submit" data-testid="bulk-simulate-registration-field-value-submit" class="text-left font-medium text-indigo-700 hover:text-indigo-900">पाठवा (simulate)</button>
                        </form>
                    </div>
                @elseif ($canSimulateRegistrationReply && $registrationSimulateButtons !== [])
                    <div class="mt-2 border-t border-violet-100 pt-2">
                        <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-violet-700">Simulate registration reply</span>
                        @if ($canSimulateRegistrationPhoto)
                            <form method="POST" action="{{ route('admin.bulk-intakes.items.simulate-registration-photo', [$batch, $item]) }}" class="mt-1 mb-2">
                                @csrf
                                <button
                                    type="submit"
                                    data-testid="bulk-simulate-registration-photo-sent"
                                    class="text-left font-medium text-emerald-700 hover:text-emerald-900"
                                >📷 Simulate photo sent (test)</button>
                            </form>
                            <p class="mb-1 text-[10px] text-violet-700">PDF बायोडाट्यासाठी “हो वापरा” ऐवजी हे बटण वापरा.</p>
                        @endif
                        @foreach ($registrationSimulateButtons as $registrationSimulateButton)
                            @php
                                $registrationReplyChoice = (string) ($registrationSimulateButton['id'] ?? '');
                                $registrationReplyLabel = trim((string) ($registrationSimulateButton['title'] ?? $registrationReplyChoice));
                                $registrationReplyTestId = match ($registrationReplyChoice) {
                                    \App\Services\Intake\BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_OK => 'bulk-simulate-registration-yes',
                                    \App\Services\Intake\BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_EDIT => 'bulk-simulate-registration-edit',
                                    \App\Services\Intake\BulkIntakeWhatsAppRegistrationConversationService::BTN_SUMMARY_LATER => 'bulk-simulate-registration-later',
                                    \App\Services\Intake\BulkIntakeWhatsAppRegistrationConversationService::BTN_PHOTO_USE => 'bulk-simulate-registration-photo-use',
                                    \App\Services\Intake\BulkIntakeWhatsAppRegistrationConversationService::BTN_PHOTO_NEW => 'bulk-simulate-registration-photo-new',
                                    default => 'bulk-simulate-registration-reply',
                                };
                            @endphp
                            <form method="POST" action="{{ route('admin.bulk-intakes.items.simulate-registration-reply', [$batch, $item]) }}" class="mt-1">
                                @csrf
                                <input type="hidden" name="reply_choice" value="{{ $registrationReplyChoice }}">
                                <button
                                    type="submit"
                                    data-testid="{{ $registrationReplyTestId }}"
                                    class="text-left font-medium text-indigo-700 hover:text-indigo-900"
                                >{{ $registrationReplyLabel }}</button>
                            </form>
                        @endforeach
                    </div>
                @endif
                @if ($consentReceived && ($registrationSummary['public_url'] ?? '') !== '')
                    <a href="{{ $registrationSummary['public_url'] }}" target="_blank" rel="noopener" data-testid="bulk-registration-web-edit" class="mt-2 block font-medium text-indigo-700 hover:text-indigo-900">वेबवर सर्व edit करा (user link)</a>
                @endif
                @if ($canSimulateRegistrationComplete)
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.simulate-registration-complete', [$batch, $item]) }}" class="mt-2">
                        @csrf
                        <button type="submit" data-testid="bulk-simulate-registration-complete" class="text-left font-medium text-emerald-700 hover:text-emerald-900">नोंदणी पूर्ण करा (simulate)</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</dialog>
