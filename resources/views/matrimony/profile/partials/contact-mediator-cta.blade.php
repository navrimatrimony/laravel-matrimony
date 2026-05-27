{{-- Sole UI for WhatsApp response request: Contact Information section only. --}}
@php
    $whatsappResponseEnabled = app(\App\Services\MediationRequestService::class)->isEnabled();
    $latestOutgoing = $latestMediatorRequest ?? null;
    $latestIncoming = $latestMediatorRequestIncoming ?? null;
    $incomingRequesterProfile = $latestIncoming?->senderProfile ?? $latestIncoming?->sender?->matrimonyProfile ?? null;
    $incomingRequesterName = $incomingRequesterProfile?->full_name ?? $latestIncoming?->sender?->name ?? __('mediation.someone');
    $outgoingReceiverProfile = $latestOutgoing?->receiverProfile ?? $latestOutgoing?->receiver?->matrimonyProfile ?? $profile ?? null;
    $outgoingReceiverName = $outgoingReceiverProfile?->full_name ?? $latestOutgoing?->receiver?->name ?? __('mediation.someone');
    $showMediatorSurface = (bool) ($contactAccess['show_mediator_cta'] ?? false);
    $hasPendingOutgoing = $latestOutgoing
        && (string) $latestOutgoing->status === \App\Models\MediationRequest::STATUS_PENDING
        && ! $latestOutgoing->isDeliveryExpired()
        && $latestOutgoing->delivery_status !== \App\Models\MediationRequest::DELIVERY_CANCELLED;
    $latestMediatorStatusKey = $latestOutgoing ? 'mediation.status_'.(string) $latestOutgoing->status : null;
    $latestMediatorNamedStatusKey = $latestOutgoing ? 'mediation.status_'.(string) $latestOutgoing->status.'_named' : null;
    $latestDeliveryKey = $latestOutgoing ? 'mediation.delivery_'.$latestOutgoing->effectiveDeliveryStatus() : null;
    $receiverFeedback = $latestOutgoing
        ? trim((string) ($latestOutgoing->response_feedback ?? data_get($latestOutgoing->meta, 'matchmaking.receiver_feedback', '')))
        : '';
    $outgoingHasResponse = $latestOutgoing?->hasResponded() ?? false;
    $incomingDeliveryKey = $latestIncoming ? 'mediation.delivery_'.$latestIncoming->effectiveDeliveryStatus() : null;
    $incomingStatusKey = $latestIncoming ? 'mediation.status_'.(string) $latestIncoming->status : null;
    $incomingNamedStatusKey = $latestIncoming ? 'mediation.incoming_status_'.(string) $latestIncoming->status.'_named' : null;
    $incomingResponseChoice = $latestIncoming ? data_get($latestIncoming->meta, 'matchmaking.receiver_choice') : null;
    $incomingDeclineReason = $latestIncoming ? data_get($latestIncoming->meta, 'matchmaking.receiver_decline_reason') : null;
    $incomingDeclineReasonNote = $latestIncoming ? data_get($latestIncoming->meta, 'matchmaking.receiver_decline_reason_note') : null;
    $incomingNextAction = $latestIncoming ? data_get($latestIncoming->meta, 'matchmaking.receiver_next_action') : null;
    $incomingFeedback = $latestIncoming
        ? trim((string) ($latestIncoming->response_feedback ?? data_get($latestIncoming->meta, 'matchmaking.receiver_feedback', '')))
        : '';
    $incomingCanRespond = $latestIncoming
        && $latestIncoming->isPending()
        && ! $latestIncoming->isDeliveryExpired()
        && $latestIncoming->delivery_status !== \App\Models\MediationRequest::DELIVERY_CANCELLED;
    $outgoingResponseChoice = $latestOutgoing ? data_get($latestOutgoing->meta, 'matchmaking.receiver_choice') : null;
    $outgoingDeclineReason = $latestOutgoing ? data_get($latestOutgoing->meta, 'matchmaking.receiver_decline_reason') : null;
    $outgoingDeclineReasonNote = $latestOutgoing ? data_get($latestOutgoing->meta, 'matchmaking.receiver_decline_reason_note') : null;
    $outgoingNextAction = $latestOutgoing ? data_get($latestOutgoing->meta, 'matchmaking.receiver_next_action') : null;
    $formatResponseChoice = static fn (?string $choice): ?string => match ($choice) {
        'interested' => __('mediation.interested'),
        'not_interested' => __('mediation.not_interested'),
        'need_more_info' => __('mediation.need_more_info'),
        'decide_later' => __('mediation.decide_later'),
        'talks_in_progress' => __('mediation.talks_in_progress'),
        default => null,
    };
    $formatDeclineReason = static fn (?string $reason): ?string => match ($reason) {
        'age_mismatch' => __('mediation.reason_age_mismatch'),
        'education_mismatch' => __('mediation.reason_education_mismatch'),
        'location_mismatch' => __('mediation.reason_location_mismatch'),
        'job_income_mismatch' => __('mediation.reason_job_income_mismatch'),
        'caste_subcaste_mismatch' => __('mediation.reason_caste_subcaste_mismatch'),
        'horoscope_mismatch' => __('mediation.reason_horoscope_mismatch'),
        'talks_in_progress' => __('mediation.reason_talks_in_progress'),
        'marriage_fixed' => __('mediation.reason_marriage_fixed'),
        'other' => __('mediation.reason_other'),
        default => null,
    };
    $formatNextAction = static fn (?string $action): ?string => match ($action) {
        'share_my_number' => __('mediation.next_action_share_my_number'),
        'view_their_number' => __('mediation.next_action_view_their_number'),
        'app_chat' => __('mediation.next_action_app_chat'),
        'office_contact' => __('mediation.next_action_office_contact'),
        default => null,
    };
@endphp

@if ($latestIncoming)
    <div class="mb-4 rounded-2xl border border-sky-200 bg-gradient-to-br from-sky-50 via-white to-indigo-50/60 p-4 shadow-sm ring-1 ring-sky-100 dark:border-sky-800/50 dark:from-sky-950/30 dark:via-gray-900 dark:to-indigo-950/20 dark:ring-sky-900/30">
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-sky-700 dark:text-sky-300">{{ __('mediation.incoming_whatsapp_response_kicker') }}</p>
        <p class="mt-1 text-sm font-semibold text-sky-950 dark:text-sky-100">{{ __('mediation.incoming_whatsapp_response_title_named', ['name' => $incomingRequesterName]) }}</p>
        <p class="mt-1 text-xs leading-5 text-sky-900/85 dark:text-sky-100/90">
            {{ $incomingCanRespond ? __('mediation.incoming_whatsapp_response_body_inline') : __('mediation.incoming_whatsapp_response_body_done') }}
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($incomingStatusKey)
                <span class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-900 dark:bg-sky-900/40 dark:text-sky-100">
                    {{ __($incomingNamedStatusKey, ['name' => $incomingRequesterName]) }}
                </span>
            @endif
            @if ($incomingDeliveryKey)
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-900 dark:bg-emerald-900/35 dark:text-emerald-100">
                    {{ __($incomingDeliveryKey) }}
                </span>
            @endif
        </div>
        @if ($incomingCanRespond)
            <form method="POST" action="{{ route('mediation-requests.respond', $latestIncoming) }}" class="mt-4 space-y-4 border-t border-sky-200 pt-4 dark:border-sky-800/50" x-data="{ response: '', declineReason: '' }">
                @csrf
                <fieldset>
                    <legend class="mb-2 text-sm font-semibold text-sky-950 dark:text-sky-100">{{ __('mediation.your_response') }}</legend>
                    <div class="grid gap-2 text-sm sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-sky-200 bg-white/80 px-3 py-2 dark:border-sky-800/50 dark:bg-gray-900/50">
                            <input type="radio" name="response" value="interested" x-model="response" required>
                            <span>{{ __('mediation.interested') }}</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-sky-200 bg-white/80 px-3 py-2 dark:border-sky-800/50 dark:bg-gray-900/50">
                            <input type="radio" name="response" value="not_interested" x-model="response">
                            <span>{{ __('mediation.not_interested') }}</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-sky-200 bg-white/80 px-3 py-2 dark:border-sky-800/50 dark:bg-gray-900/50">
                            <input type="radio" name="response" value="need_more_info" x-model="response">
                            <span>{{ __('mediation.need_more_info') }}</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-sky-200 bg-white/80 px-3 py-2 dark:border-sky-800/50 dark:bg-gray-900/50">
                            <input type="radio" name="response" value="decide_later" x-model="response">
                            <span>{{ __('mediation.decide_later') }}</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-sky-200 bg-white/80 px-3 py-2 dark:border-sky-800/50 dark:bg-gray-900/50 sm:col-span-2">
                            <input type="radio" name="response" value="talks_in_progress" x-model="response">
                            <span>{{ __('mediation.talks_in_progress') }}</span>
                        </label>
                    </div>
                </fieldset>

                <div x-show="response === 'not_interested' || response === 'talks_in_progress'" x-cloak class="space-y-3 rounded-xl bg-white/75 p-3 dark:bg-gray-900/45">
                    <label for="profile-decline-reason-{{ $latestIncoming->id }}" class="block text-sm font-medium text-sky-950 dark:text-sky-100">{{ __('mediation.decline_reason_label') }}</label>
                    <select id="profile-decline-reason-{{ $latestIncoming->id }}" name="decline_reason" x-model="declineReason" class="w-full rounded-md border border-sky-200 text-sm dark:border-sky-800 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">{{ __('mediation.decline_reason_placeholder') }}</option>
                        <option value="age_mismatch">{{ __('mediation.reason_age_mismatch') }}</option>
                        <option value="education_mismatch">{{ __('mediation.reason_education_mismatch') }}</option>
                        <option value="location_mismatch">{{ __('mediation.reason_location_mismatch') }}</option>
                        <option value="job_income_mismatch">{{ __('mediation.reason_job_income_mismatch') }}</option>
                        <option value="caste_subcaste_mismatch">{{ __('mediation.reason_caste_subcaste_mismatch') }}</option>
                        <option value="horoscope_mismatch">{{ __('mediation.reason_horoscope_mismatch') }}</option>
                        <option value="talks_in_progress">{{ __('mediation.reason_talks_in_progress') }}</option>
                        <option value="marriage_fixed">{{ __('mediation.reason_marriage_fixed') }}</option>
                        <option value="other">{{ __('mediation.reason_other') }}</option>
                    </select>
                    <div x-show="declineReason === 'other'" x-cloak>
                        <label for="profile-decline-note-{{ $latestIncoming->id }}" class="mb-1 block text-sm font-medium text-sky-950 dark:text-sky-100">{{ __('mediation.decline_reason_note_label') }}</label>
                        <input id="profile-decline-note-{{ $latestIncoming->id }}" name="decline_reason_note" type="text" maxlength="500" class="w-full rounded-md border border-sky-200 text-sm dark:border-sky-800 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                </div>

                <fieldset x-show="response === 'interested'" x-cloak class="rounded-xl bg-emerald-50/90 p-3 dark:bg-emerald-950/30">
                    <legend class="mb-2 text-sm font-medium text-sky-950 dark:text-sky-100">{{ __('mediation.next_action_label') }}</legend>
                    <div class="grid gap-2 text-sm sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="next_action" value="share_my_number"> <span>{{ __('mediation.next_action_share_my_number') }}</span></label>
                        <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="next_action" value="view_their_number"> <span>{{ __('mediation.next_action_view_their_number') }}</span></label>
                        <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="next_action" value="app_chat"> <span>{{ __('mediation.next_action_app_chat') }}</span></label>
                        <label class="flex cursor-pointer items-center gap-2"><input type="radio" name="next_action" value="office_contact"> <span>{{ __('mediation.next_action_office_contact') }}</span></label>
                    </div>
                </fieldset>

                <div>
                    <label for="profile-feedback-{{ $latestIncoming->id }}" class="mb-1 block text-sm font-medium text-sky-950 dark:text-sky-100">{{ __('mediation.feedback_optional') }}</label>
                    <textarea id="profile-feedback-{{ $latestIncoming->id }}" name="feedback" rows="3" maxlength="2000" class="w-full rounded-md border border-sky-200 text-sm dark:border-sky-800 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </div>

                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-sky-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 dark:hover:bg-sky-500">
                    {{ __('mediation.submit_response') }}
                </button>
            </form>
        @else
            <div class="mt-4 rounded-xl border border-sky-200/80 bg-white/85 p-3 text-xs text-sky-950 dark:border-sky-800/50 dark:bg-gray-900/55 dark:text-sky-100">
                @if ($formatResponseChoice($incomingResponseChoice))
                    <p><span class="font-semibold">{{ __('mediation.response_choice_label') }}:</span> {{ $formatResponseChoice($incomingResponseChoice) }}</p>
                @endif
                @if ($formatDeclineReason($incomingDeclineReason))
                    <p class="mt-1"><span class="font-semibold">{{ __('mediation.decline_reason_label') }}:</span> {{ $formatDeclineReason($incomingDeclineReason) }}</p>
                @endif
                @if ($incomingDeclineReasonNote)
                    <p class="mt-1"><span class="font-semibold">{{ __('mediation.decline_reason_note_label') }}:</span> {{ $incomingDeclineReasonNote }}</p>
                @endif
                @if ($formatNextAction($incomingNextAction))
                    <p class="mt-1"><span class="font-semibold">{{ __('mediation.next_action_label') }}:</span> {{ $formatNextAction($incomingNextAction) }}</p>
                @endif
                @if ($incomingFeedback !== '')
                    <p class="mt-1"><span class="font-semibold">{{ __('mediation.feedback_label') }}:</span> {{ $incomingFeedback }}</p>
                @endif
                @if ($latestIncoming->responded_at)
                    <p class="mt-2 text-sky-800/75 dark:text-sky-100/70">{{ __('mediation.responded_at_label', ['time' => $latestIncoming->responded_at->timezone(config('app.timezone'))->format('M j, Y H:i')]) }}</p>
                @endif
            </div>
        @endif
    </div>
@endif

@if (! $latestIncoming && ($showMediatorSurface || $latestOutgoing))
    <div class="rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-green-50/80 p-4 shadow-sm ring-1 ring-emerald-100 dark:border-emerald-800/50 dark:from-emerald-950/25 dark:via-gray-900 dark:to-emerald-950/15 dark:ring-emerald-900/30">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/45 dark:text-emerald-300" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12.04 2.002c-5.523 0-9.999 4.305-9.999 9.613 0 1.864.56 3.604 1.526 5.071L2 22l5.56-1.456a10.19 10.19 0 0 0 4.48 1.03h.004c5.52 0 9.996-4.305 9.996-9.613s-4.477-9.959-10-9.959Zm5.84 13.784c-.24.665-1.2 1.227-1.66 1.3-.425.067-.95.095-1.534-.09-.355-.113-.812-.262-1.404-.51-2.47-1.032-4.08-3.59-4.205-3.758-.122-.168-.998-1.324-.998-2.526 0-1.203.64-1.792.868-2.036.229-.244.5-.305.665-.305.168 0 .334.002.48.01.154.008.36-.058.563.415.208.498.707 1.722.769 1.846.063.124.104.272.02.44-.083.168-.125.272-.25.417-.124.145-.262.324-.374.434-.126.124-.257.259-.111.507.146.248.65 1.053 1.394 1.707.957.84 1.765 1.102 2.013 1.227.249.125.394.105.54-.063.146-.168.624-.706.79-.95.166-.243.333-.204.562-.122.229.083 1.45.672 1.699.794.248.124.414.186.477.29.062.104.062.604-.178 1.269Z"/>
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-700 dark:text-emerald-300">{{ __('contact_access.mediator_heading') }}</p>
                <p class="mt-1 text-sm leading-6 text-emerald-900/90 dark:text-emerald-100/95">
                    @if ($outgoingHasResponse)
                        {{ __('contact_access.mediator_response_received_note', ['name' => $outgoingReceiverName]) }}
                    @elseif ($hasPendingOutgoing)
                        {{ __('contact_access.mediator_response_pending_note', ['name' => $outgoingReceiverName]) }}
                    @else
                        {{ __('contact_access.mediator_side_note') }}
                    @endif
                </p>
            </div>
        </div>

        @if ($latestOutgoing)
            <div class="mt-3 rounded-xl border border-emerald-200/80 bg-white/90 p-3 text-xs dark:border-emerald-800/50 dark:bg-gray-900/60">
                <div class="flex flex-wrap gap-2">
                    @if ($latestMediatorStatusKey)
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 font-semibold text-emerald-800 dark:bg-emerald-900/35 dark:text-emerald-200">
                            {{ __($latestMediatorNamedStatusKey, ['name' => $outgoingReceiverName]) }}
                        </span>
                    @endif
                    @if ($latestDeliveryKey)
                        <span class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-1 font-semibold text-sky-800 dark:bg-sky-900/35 dark:text-sky-200">
                            {{ __($latestDeliveryKey) }}
                        </span>
                    @endif
                </div>
                @if ($receiverFeedback !== '')
                    <p class="mt-2 text-xs leading-5 text-gray-700 dark:text-gray-200">
                        <span class="font-semibold">{{ __('mediation.feedback_label') }}:</span> {{ $receiverFeedback }}
                    </p>
                @endif
                @if ($formatResponseChoice($outgoingResponseChoice))
                    <p class="mt-2 text-xs leading-5 text-gray-700 dark:text-gray-200">
                        <span class="font-semibold">{{ __('mediation.response_choice_label') }}:</span> {{ $formatResponseChoice($outgoingResponseChoice) }}
                    </p>
                @endif
                @if ($formatDeclineReason($outgoingDeclineReason))
                    <p class="mt-1 text-xs leading-5 text-gray-700 dark:text-gray-200">
                        <span class="font-semibold">{{ __('mediation.decline_reason_label') }}:</span> {{ $formatDeclineReason($outgoingDeclineReason) }}
                    </p>
                @endif
                @if ($outgoingDeclineReasonNote)
                    <p class="mt-1 text-xs leading-5 text-gray-700 dark:text-gray-200">
                        <span class="font-semibold">{{ __('mediation.decline_reason_note_label') }}:</span> {{ $outgoingDeclineReasonNote }}
                    </p>
                @endif
                @if ($formatNextAction($outgoingNextAction))
                    <p class="mt-1 text-xs leading-5 text-gray-700 dark:text-gray-200">
                        <span class="font-semibold">{{ __('mediation.next_action_label') }}:</span> {{ $formatNextAction($outgoingNextAction) }}
                    </p>
                @endif
                @if ($latestOutgoing->cooldown_ends_at && $latestOutgoing->cooldown_ends_at->isFuture())
                    <p class="mt-2 text-xs font-medium text-emerald-900/80 dark:text-emerald-100/80">
                        {{ __('mediation.request_again_after', ['date' => $latestOutgoing->cooldown_ends_at->timezone(config('app.timezone'))->format('M j, Y')]) }}
                    </p>
                @endif
            </div>
        @endif

        @if (auth()->check())
            <div class="mt-3 space-y-2">
                @if (! $whatsappResponseEnabled)
                    <button
                        type="button"
                        disabled
                        class="inline-flex w-full items-center justify-center rounded-xl border border-gray-200 bg-gray-100 px-3 py-2.5 text-sm font-semibold text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                    >
                        {{ __('contact_access.whatsapp_response_unavailable') }}
                    </button>
                @elseif ($contactAccess['needs_upgrade_for_mediator'] ?? false)
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center rounded-xl border border-amber-200/90 bg-amber-50 px-3 py-2.5 text-sm font-semibold text-amber-900 transition hover:bg-amber-100 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-100 dark:hover:bg-amber-950/60"
                        @click="$root.showContactUpgradeModal = true"
                    >
                        {{ __('contact_access.upgrade_plans') }}
                    </button>
                @elseif ($latestOutgoing && $latestOutgoing->cooldown_ends_at && $latestOutgoing->cooldown_ends_at->isFuture())
                    <button
                        type="button"
                        disabled
                        class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm opacity-70"
                    >
                        {{ __('mediation.request_again_after', ['date' => $latestOutgoing->cooldown_ends_at->timezone(config('app.timezone'))->format('M j, Y')]) }}
                    </button>
                @elseif ($showMediatorSurface)
                    <form method="POST" action="{{ route('matrimony.profile.mediator-request', $profile) }}">
                        @csrf
                        <button
                            type="submit"
                            @disabled($hasPendingOutgoing)
                            class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            <span class="block text-center leading-5">
                                {{ $hasPendingOutgoing ? __('mediation.status_pending_named', ['name' => $outgoingReceiverName]) : __('contact_access.mediator_submit') }}
                            </span>
                        </button>
                    </form>
                @endif

                @if (Route::has('mediation-inbox.index'))
                    <a href="{{ route('mediation-inbox.index') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-50 dark:border-emerald-800/60 dark:bg-gray-900 dark:text-emerald-200 dark:hover:bg-emerald-950/30">
                        {{ __('contact_access.mediator_open_inbox') }}
                    </a>
                @endif
            </div>
        @else
            <a href="{{ route('login') }}" class="mt-3 inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                {{ __('Login') }}
            </a>
        @endif
    </div>
@endif
