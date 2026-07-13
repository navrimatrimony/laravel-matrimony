@php
    $dupPanelDialogId = 'bulk-dup-panel-'.(int) $item->id;
    $dupCandidateName = trim((string) ($candidate['full_name'] ?? $item->original_filename ?? 'Candidate'));
    $dupHints = is_array($duplicateVerification['hints'] ?? null) ? $duplicateVerification['hints'] : [];
    $dupHistoryBlocks = is_array($duplicateVerification['history_blocks'] ?? null) ? $duplicateVerification['history_blocks'] : [];
    $dupHintCount = (int) ($duplicateVerification['hint_count'] ?? count($dupHints));
    $canConfirmStaleDuplicateProceed = app(\App\Services\Intake\BulkIntakeDuplicateVerificationService::class)
        ->canConfirmStaleIntakeProceed(is_array($duplicateVerification ?? null) ? $duplicateVerification : []);
@endphp

<dialog id="{{ $dupPanelDialogId }}" class="w-[min(620px,95vw)] max-h-[90vh] rounded-xl border border-gray-200 bg-white p-0 shadow-xl backdrop:bg-black/40">
    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">Duplicate verify</h3>
            <p class="text-xs text-gray-500">{{ $dupCandidateName }} · Item #{{ (int) $item->id }}</p>
        </div>
        <button type="button" data-bulk-dup-close class="rounded-md px-2 py-1 text-lg leading-none text-gray-500 hover:bg-gray-100" aria-label="Close">×</button>
    </div>
    <div class="max-h-[calc(90vh-4.5rem)] space-y-4 overflow-y-auto p-4 text-sm">
        @if ($dupHistoryBlocks !== [])
            <div class="rounded-md border border-red-200 bg-red-50 p-3">
                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-red-700">Current identity history</span>
                <ul class="space-y-1 text-xs text-red-900">
                    @foreach ($dupHistoryBlocks as $historyBlock)
                        <li>• {{ $historyBlock['label'] ?? $historyBlock['reason_code'] ?? 'Blocked' }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @forelse ($dupHints as $dupHintIndex => $dupHint)
            @php
                $matched = is_array($dupHint['matched'] ?? null) ? $dupHint['matched'] : [];
                $links = is_array($matched['links'] ?? null) ? $matched['links'] : [];
                $historyFlags = is_array($matched['history_flags'] ?? null) ? $matched['history_flags'] : [];
                $recommendedAction = (string) ($dupHint['recommended_action'] ?? 'review');
                $recommendedClass = match ($recommendedAction) {
                    \App\Services\Intake\BulkIntakeDuplicateVerificationService::ACTION_BLOCK => 'border-rose-200 bg-rose-50 text-rose-900',
                    \App\Services\Intake\BulkIntakeDuplicateVerificationService::ACTION_PROCEED_OK => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                    default => 'border-amber-200 bg-amber-50 text-amber-900',
                };
            @endphp
            <section class="rounded-lg border border-gray-200 bg-gray-50/80 p-3" data-testid="bulk-duplicate-verify-hint">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-purple-700">Match {{ $dupHintIndex + 1 }}</span>
                    <span class="rounded-full border border-purple-200 bg-purple-50 px-2 py-0.5 text-[10px] font-semibold text-purple-800">
                        {{ $dupHint['reason_label_mr'] ?? $dupHint['label'] ?? 'Duplicate' }}
                    </span>
                    @if (! empty($dupHint['confidence']))
                        <span class="text-[10px] text-gray-500">{{ $dupHint['confidence'] }} confidence</span>
                    @endif
                </div>

                <div class="mt-2 space-y-1 text-xs text-gray-800">
                    @if (! empty($matched['intake_id']))
                        <p><span class="font-medium">Intake:</span> #{{ (int) $matched['intake_id'] }}</p>
                    @endif
                    @if (! empty($matched['batch_id']))
                        <p>
                            <span class="font-medium">Batch:</span>
                            #{{ (int) $matched['batch_id'] }}
                            @if (! empty($matched['batch_name']))
                                — {{ $matched['batch_name'] }}
                            @endif
                            @if (! empty($matched['item_sequence']))
                                · item {{ (int) $matched['item_sequence'] }}
                            @endif
                        </p>
                    @endif
                    @if (! empty($matched['uploaded_at']))
                        <p><span class="font-medium">Uploaded:</span> {{ $matched['uploaded_at'] }}</p>
                    @endif
                    @if (! empty($matched['full_name']))
                        <p><span class="font-medium">नाव:</span> {{ $matched['full_name'] }}</p>
                    @endif
                    @if (! empty($matched['mobile']))
                        <p><span class="font-medium">मोबाईल:</span> {{ $matched['mobile'] }}</p>
                    @endif
                    @if (! empty($matched['date_of_birth']))
                        <p><span class="font-medium">जन्मतारीख:</span> {{ $matched['date_of_birth'] }}</p>
                    @endif
                </div>

                <div class="mt-2 rounded-md border border-sky-200 bg-sky-50 p-2 text-xs text-sky-950">
                    <p class="font-semibold">{{ $matched['journey_label'] ?? 'Journey unknown' }}</p>
                    @if (! empty($matched['journey_detail']))
                        <p class="mt-0.5">{{ $matched['journey_detail'] }}</p>
                    @endif
                    @if (! empty($matched['consent_status_label']))
                        <p class="mt-1"><span class="font-medium">Consent:</span> {{ $matched['consent_status_label'] }}</p>
                    @endif
                    @if (! empty($matched['registration_status_label']))
                        <p><span class="font-medium">Registration:</span> {{ $matched['registration_status_label'] }}</p>
                    @endif
                    @if (! empty($matched['profile_id']))
                        <p><span class="font-medium">Profile:</span> #{{ (int) $matched['profile_id'] }}</p>
                    @endif
                </div>

                @if ($historyFlags !== [])
                    <div class="mt-2 rounded-md border border-red-100 bg-red-50/70 p-2 text-xs text-red-900">
                        <p class="font-semibold">जुना history</p>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($historyFlags as $historyFlag)
                                <li>• {{ $historyFlag['label'] ?? $historyFlag['code'] ?? 'History' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <p class="mt-2 rounded-md border px-2 py-1.5 text-xs {{ $recommendedClass }}">
                    {{ $dupHint['recommended_label_mr'] ?? 'तपासा.' }}
                </p>

                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    @if (! empty($links['intake']))
                        <a href="{{ $links['intake'] }}" target="_blank" rel="noopener" data-testid="bulk-duplicate-link-intake" class="font-medium text-indigo-700 hover:text-indigo-900">जुना intake</a>
                    @endif
                    @if (! empty($links['batch']))
                        <a href="{{ $links['batch'] }}" target="_blank" rel="noopener" data-testid="bulk-duplicate-link-batch" class="font-medium text-indigo-700 hover:text-indigo-900">जुना batch</a>
                    @endif
                    @if (! empty($links['correct_candidate']))
                        <a href="{{ $links['correct_candidate'] }}" target="_blank" rel="noopener" data-testid="bulk-duplicate-link-correct" class="font-medium text-emerald-700 hover:text-emerald-900">Correct candidate</a>
                    @endif
                    @if (! empty($links['profile']))
                        <a href="{{ $links['profile'] }}" target="_blank" rel="noopener" data-testid="bulk-duplicate-link-profile" class="font-medium text-violet-700 hover:text-violet-900">Profile</a>
                    @endif
                </div>
            </section>
        @empty
            <p class="text-xs text-gray-500">No duplicate hints for this item.</p>
        @endforelse

        <div class="border-t border-gray-200 pt-3">
            <span class="mb-2 block text-[10px] font-semibold uppercase tracking-wide text-gray-500">Admin actions</span>
            <div class="flex flex-col gap-2">
                @if ($manualDuplicateActive)
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-duplicate', [$batch, $item]) }}">
                        @csrf
                        <button type="submit" class="text-left font-medium text-rose-700 hover:text-rose-900">Clear duplicate mark</button>
                    </form>
                @elseif ($dupHints !== [])
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]) }}">
                        @csrf
                        @php $primaryDupHint = is_array($dupHints[0] ?? null) ? $dupHints[0] : []; @endphp
                        @if (! empty($primaryDupHint['matched_intake_id']))
                            <input type="hidden" name="matched_biodata_intake_id" value="{{ (int) $primaryDupHint['matched_intake_id'] }}">
                        @endif
                        @if (! empty($primaryDupHint['matched_profile_id']))
                            <input type="hidden" name="matched_profile_id" value="{{ (int) $primaryDupHint['matched_profile_id'] }}">
                        @endif
                        <input type="hidden" name="reason" value="{{ trim('Duplicate verify: '.(string) ($primaryDupHint['reason_label_mr'] ?? $primaryDupHint['label'] ?? 'Possible duplicate')) }}">
                        <button type="submit" data-testid="bulk-duplicate-mark-from-panel" class="text-left font-medium text-rose-700 hover:text-rose-900">Same person — mark duplicate</button>
                    </form>
                @endif

                @if ($canConfirmStaleDuplicateProceed && ! $duplicateOverrideActive && ! $manualDuplicateActive)
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.override-duplicate-block', [$batch, $item]) }}">
                        @csrf
                        <input type="hidden" name="reason" value="Admin verified — जुना upload फक्त intake, नवीन consent process">
                        <button
                            type="submit"
                            data-testid="bulk-confirm-duplicate-proceed"
                            class="rounded-md border border-emerald-300 bg-emerald-50 px-2 py-1.5 text-left text-sm font-semibold text-emerald-900 hover:bg-emerald-100"
                        >जुना फक्त intake होता — consent साठी पुढे जा</button>
                    </form>
                @elseif ($duplicateAutoBlocked && ! $duplicateOverrideActive && ! $manualDuplicateActive)
                    <form method="POST" action="{{ route('admin.bulk-intakes.items.override-duplicate-block', [$batch, $item]) }}">
                        @csrf
                        <input type="hidden" name="reason" value="Admin override after duplicate verify">
                        <button type="submit" data-testid="bulk-override-duplicate-block" class="text-left font-medium text-sky-700 hover:text-sky-900">Old upload was dead — proceed</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</dialog>
