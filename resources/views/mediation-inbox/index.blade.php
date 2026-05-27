@extends('layouts.app')

@section('content')
<div class="py-8 max-w-4xl mx-auto px-4">
    <h1 class="mb-6 inline-flex items-center gap-3 text-2xl font-bold text-gray-900 dark:text-gray-100">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 text-[#25D366] dark:bg-emerald-950/50" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        </span>
        <span>{{ __('mediation.inbox_title') }}</span>
    </h1>

    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 mb-4">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p class="text-red-600 dark:text-red-400 mb-4">{{ session('error') }}</p>
    @endif

    @php
        $formatResponseChoice = static fn (?string $choice): string => match ($choice) {
            'interested' => __('mediation.interested'),
            'not_interested' => __('mediation.not_interested'),
            'need_more_info' => __('mediation.need_more_info'),
            'decide_later' => __('mediation.decide_later'),
            'talks_in_progress' => __('mediation.talks_in_progress'),
            default => $choice ? str_replace('_', ' ', $choice) : '',
        };

        $formatDeclineReason = static fn (?string $reason): string => match ($reason) {
            'age_mismatch' => __('mediation.reason_age_mismatch'),
            'education_mismatch' => __('mediation.reason_education_mismatch'),
            'location_mismatch' => __('mediation.reason_location_mismatch'),
            'job_income_mismatch' => __('mediation.reason_job_income_mismatch'),
            'caste_subcaste_mismatch' => __('mediation.reason_caste_subcaste_mismatch'),
            'horoscope_mismatch' => __('mediation.reason_horoscope_mismatch'),
            'talks_in_progress' => __('mediation.reason_talks_in_progress'),
            'marriage_fixed' => __('mediation.reason_marriage_fixed'),
            'other' => __('mediation.reason_other'),
            default => $reason ? str_replace('_', ' ', $reason) : '',
        };

        $formatNextAction = static fn (?string $action): string => match ($action) {
            'share_my_number' => __('mediation.next_action_share_my_number'),
            'view_their_number' => __('mediation.next_action_view_their_number'),
            'app_chat' => __('mediation.next_action_app_chat'),
            'office_contact' => __('mediation.next_action_office_contact'),
            default => $action ? str_replace('_', ' ', $action) : '',
        };
    @endphp

    <div x-data="{ tab: 'pending' }" class="space-y-8">
        <div class="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700">
            <button type="button" @click="tab = 'pending'" :class="tab === 'pending' ? 'border-b-2 border-indigo-600 text-indigo-600 font-medium' : 'text-gray-600 dark:text-gray-400'" class="pb-2 px-2 text-sm">
                {{ __('mediation.pending_heading') }}
                @if($pending->count() > 0)<span class="ml-1 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">{{ $pending->count() }}</span>@endif
            </button>
            <button type="button" @click="tab = 'history'" :class="tab === 'history' ? 'border-b-2 border-indigo-600 text-indigo-600 font-medium' : 'text-gray-600 dark:text-gray-400'" class="pb-2 px-2 text-sm">
                {{ __('mediation.history_heading') }}
            </button>
            <button type="button" @click="tab = 'outgoing'" :class="tab === 'outgoing' ? 'border-b-2 border-indigo-600 text-indigo-600 font-medium' : 'text-gray-600 dark:text-gray-400'" class="pb-2 px-2 text-sm">
                {{ __('mediation.outgoing_heading') }}
            </button>
        </div>

        <div x-show="tab === 'pending'" x-cloak class="space-y-4">
            @forelse ($pending as $mr)
                @php
                    $sender = $mr->sender;
                    $sp = $sender->matrimonyProfile ?? null;
                    $receiverProfile = $mr->receiverProfile;
                    $hint = data_get($mr->meta, 'matchmaking.compatibility_hint');
                    $deliveryStatus = $mr->effectiveDeliveryStatus();
                @endphp
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-800">
                    <p class="font-semibold text-gray-900 dark:text-gray-100">{{ __('mediation.requested_by') }}: {{ $sp->full_name ?? $sender->name }}</p>
                    @if ($sp)
                        <a href="{{ route('matrimony.profile.show', $sp->id) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('mediation.view_profile') }}</a>
                    @endif
                    @if ($hint)
                        <p class="mt-3 rounded-md bg-stone-50 px-3 py-2 text-sm text-stone-700 dark:bg-stone-900/40 dark:text-stone-200">{{ $hint }}</p>
                    @endif
                    @if ($mr->subjectProfile && $mr->subjectProfile->id !== optional($sp)->id && $mr->subjectProfile->id !== optional($receiverProfile)->id)
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('mediation.about_profile') }}:
                            <a href="{{ route('matrimony.profile.show', $mr->subjectProfile->id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $mr->subjectProfile->full_name ?? __('Profile') }}</a>
                        </p>
                    @endif
                    <p class="mt-1 text-xs text-gray-500">{{ $mr->created_at->diffForHumans() }}</p>
                    <p class="mt-2 inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                        {{ __('mediation.delivery_'.$deliveryStatus) }}
                    </p>

                    @if ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_EXPIRED || $deliveryStatus === \App\Models\MediationRequest::DELIVERY_CANCELLED)
                        <p class="mt-4 rounded-md bg-stone-50 px-3 py-2 text-sm text-stone-700 dark:bg-stone-900/40 dark:text-stone-200">
                            {{ $deliveryStatus === \App\Models\MediationRequest::DELIVERY_CANCELLED ? __('mediation.request_cancelled') : __('mediation.request_expired') }}
                        </p>
                    @else
                        <form method="POST" action="{{ route('mediation-requests.respond', $mr) }}" class="mt-4 space-y-4 border-t border-stone-200 pt-4 dark:border-gray-600" x-data="{ response: '{{ old('response') }}', declineReason: '{{ old('decline_reason') }}' }">
                            @csrf
                            <fieldset>
                                <legend class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('mediation.your_response') }}</legend>
                                <div class="grid gap-2 text-sm sm:grid-cols-2">
                                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-600">
                                        <input type="radio" name="response" value="interested" x-model="response" class="rounded border-gray-300 dark:border-gray-600" required>
                                        <span>{{ __('mediation.interested') }}</span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-600">
                                        <input type="radio" name="response" value="not_interested" x-model="response" class="rounded border-gray-300 dark:border-gray-600">
                                        <span>{{ __('mediation.not_interested') }}</span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-600">
                                        <input type="radio" name="response" value="need_more_info" x-model="response" class="rounded border-gray-300 dark:border-gray-600">
                                        <span>{{ __('mediation.need_more_info') }}</span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-600">
                                        <input type="radio" name="response" value="decide_later" x-model="response" class="rounded border-gray-300 dark:border-gray-600">
                                        <span>{{ __('mediation.decide_later') }}</span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-600 sm:col-span-2">
                                        <input type="radio" name="response" value="talks_in_progress" x-model="response" class="rounded border-gray-300 dark:border-gray-600">
                                        <span>{{ __('mediation.talks_in_progress') }}</span>
                                    </label>
                                </div>
                            </fieldset>

                        <div x-show="response === 'not_interested' || response === 'talks_in_progress'" x-cloak class="space-y-3 rounded-md bg-stone-50 p-3 dark:bg-stone-900/40">
                            <label for="decline-reason-{{ $mr->id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('mediation.decline_reason_label') }}</label>
                            <select id="decline-reason-{{ $mr->id }}" name="decline_reason" x-model="declineReason" class="w-full rounded-md border border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
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
                                <label for="decline-note-{{ $mr->id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('mediation.decline_reason_note_label') }}</label>
                                <input id="decline-note-{{ $mr->id }}" name="decline_reason_note" type="text" maxlength="500" value="{{ old('decline_reason_note') }}" class="w-full rounded-md border border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                        </div>

                        <fieldset x-show="response === 'interested'" x-cloak class="rounded-md bg-emerald-50 p-3 dark:bg-emerald-950/30">
                            <legend class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('mediation.next_action_label') }}</legend>
                            <div class="grid gap-2 text-sm sm:grid-cols-2">
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input type="radio" name="next_action" value="share_my_number" class="rounded border-gray-300 dark:border-gray-600">
                                    <span>{{ __('mediation.next_action_share_my_number') }}</span>
                                </label>
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input type="radio" name="next_action" value="view_their_number" class="rounded border-gray-300 dark:border-gray-600">
                                    <span>{{ __('mediation.next_action_view_their_number') }}</span>
                                </label>
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input type="radio" name="next_action" value="app_chat" class="rounded border-gray-300 dark:border-gray-600">
                                    <span>{{ __('mediation.next_action_app_chat') }}</span>
                                </label>
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input type="radio" name="next_action" value="office_contact" class="rounded border-gray-300 dark:border-gray-600">
                                    <span>{{ __('mediation.next_action_office_contact') }}</span>
                                </label>
                            </div>
                        </fieldset>

                        <div>
                            <label for="feedback-{{ $mr->id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('mediation.feedback_optional') }}</label>
                            <textarea id="feedback-{{ $mr->id }}" name="feedback" rows="3" maxlength="2000" class="w-full rounded-md border border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        </div>
                            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('mediation.submit_response') }}</button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="space-y-1 text-gray-600 dark:text-gray-400">
                    <p>{{ __('mediation.empty_pending') }}</p>
                    <p class="text-sm">{{ __('mediation.empty_pending_help') }}</p>
                </div>
            @endforelse
        </div>

        <div x-show="tab === 'history'" x-cloak class="space-y-4">
            @forelse ($responded as $mr)
                @php
                    $sender = $mr->sender;
                    $sp = $mr->senderProfile ?? $sender->matrimonyProfile ?? null;
                    $receiverProfile = $mr->receiverProfile;
                    $receiverChoice = data_get($mr->meta, 'matchmaking.receiver_choice');
                    $declineReason = data_get($mr->meta, 'matchmaking.receiver_decline_reason');
                    $declineReasonNote = data_get($mr->meta, 'matchmaking.receiver_decline_reason_note');
                    $nextAction = data_get($mr->meta, 'matchmaking.receiver_next_action');
                @endphp
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-800">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('mediation.requested_by') }}: {{ $sp->full_name ?? $sender->name }}</p>
                            <p class="mt-1 font-medium text-gray-900 dark:text-gray-100">
                                @if ($receiverChoice === 'decide_later')
                                    {{ __('mediation.responded_decide_later') }}
                                @elseif ($receiverChoice === 'talks_in_progress')
                                    {{ __('mediation.responded_talks_in_progress') }}
                                @elseif ($mr->status === \App\Models\MediationRequest::STATUS_INTERESTED)
                                    {{ __('mediation.responded_interested') }}
                                @elseif ($mr->status === \App\Models\MediationRequest::STATUS_NEED_MORE_INFO)
                                    {{ __('mediation.responded_need_more_info') }}
                                @else
                                    {{ __('mediation.responded_not_interested') }}
                                @endif
                            </p>
                        </div>
                        @if ($sp)
                            <a href="{{ route('matrimony.profile.show', $sp->id) }}" class="inline-flex items-center justify-center rounded-md border border-indigo-200 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/30">{{ __('mediation.view_profile') }}</a>
                        @endif
                    </div>

                    @if ($mr->subjectProfile && $mr->subjectProfile->id !== optional($sp)->id && $mr->subjectProfile->id !== optional($receiverProfile)->id)
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('mediation.about_profile') }}:
                            <a href="{{ route('matrimony.profile.show', $mr->subjectProfile->id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $mr->subjectProfile->full_name ?? __('Profile') }}</a>
                        </p>
                    @endif

                    <div class="mt-4 rounded-md border border-sky-100 bg-sky-50/70 px-3 py-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/25 dark:text-sky-100">
                        @if ($receiverChoice)
                            <p><span class="font-semibold">{{ __('mediation.response_choice_label') }}:</span> {{ $formatResponseChoice($receiverChoice) }}</p>
                        @endif
                        @if ($declineReason)
                            <p class="mt-1"><span class="font-semibold">{{ __('mediation.decline_reason_label') }}:</span> {{ $formatDeclineReason($declineReason) }}</p>
                        @endif
                        @if ($declineReasonNote)
                            <p class="mt-1"><span class="font-semibold">{{ __('mediation.decline_reason_note_label') }}:</span> {{ $declineReasonNote }}</p>
                        @endif
                        @if ($nextAction)
                            <p class="mt-1"><span class="font-semibold">{{ __('mediation.next_action_label') }}:</span> {{ $formatNextAction($nextAction) }}</p>
                        @endif
                        @if ($mr->response_feedback)
                            <p class="mt-1"><span class="font-semibold">{{ __('mediation.feedback_label') }}:</span> {{ $mr->response_feedback }}</p>
                        @endif
                        @if ($mr->responded_at)
                            <p class="mt-2 text-sky-800/75 dark:text-sky-100/70">{{ __('mediation.responded_at_label', ['time' => $mr->responded_at->timezone(config('app.timezone'))->format('M j, Y H:i')]) }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-gray-600 dark:text-gray-400">{{ __('mediation.empty_history') }}</p>
            @endforelse
        </div>

        <div x-show="tab === 'outgoing'" x-cloak class="space-y-4">
            @forelse ($outgoing as $mr)
                @php
                    $recv = $mr->receiver;
                    $rp = $mr->receiverProfile ?? $recv->matrimonyProfile ?? null;
                    $receiverName = $rp->full_name ?? $recv->name ?? __('mediation.someone');
                    $receiverChoice = data_get($mr->meta, 'matchmaking.receiver_choice');
                    $declineReason = data_get($mr->meta, 'matchmaking.receiver_decline_reason');
                    $declineReasonNote = data_get($mr->meta, 'matchmaking.receiver_decline_reason_note');
                    $nextAction = data_get($mr->meta, 'matchmaking.receiver_next_action');
                    $deliveryStatus = $mr->effectiveDeliveryStatus();
                @endphp
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-800">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('To') }}: {{ $rp->full_name ?? $recv->name }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                                @if ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_EXPIRED)
                                    {{ __('mediation.delivery_expired') }}
                                @elseif ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_RESPONDED)
                                    {{ __('mediation.delivery_responded') }}
                                @elseif ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_REMINDER_DUE)
                                    {{ __('mediation.delivery_reminder_due') }}
                                @elseif ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_REMINDER_SENT)
                                    {{ __('mediation.delivery_reminder_sent') }}
                                @elseif ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_SENT)
                                    {{ __('mediation.delivery_sent') }}
                                @elseif ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_QUEUED)
                                    {{ __('mediation.delivery_queued') }}
                                @elseif ($deliveryStatus === \App\Models\MediationRequest::DELIVERY_CANCELLED)
                                    {{ __('mediation.delivery_cancelled') }}
                                @elseif ($mr->status === \App\Models\MediationRequest::STATUS_PENDING)
                                    {{ __('mediation.status_pending_named', ['name' => $receiverName]) }}
                                @elseif ($receiverChoice === 'decide_later')
                                    {{ __('mediation.status_decide_later_named', ['name' => $receiverName]) }}
                                @elseif ($receiverChoice === 'talks_in_progress')
                                    {{ __('mediation.status_talks_in_progress_named', ['name' => $receiverName]) }}
                                @elseif ($mr->status === \App\Models\MediationRequest::STATUS_INTERESTED)
                                    {{ __('mediation.status_interested_named', ['name' => $receiverName]) }}
                                @elseif ($mr->status === \App\Models\MediationRequest::STATUS_NEED_MORE_INFO)
                                    {{ __('mediation.status_need_more_info_named', ['name' => $receiverName]) }}
                                @else
                                    {{ __('mediation.status_not_interested_named', ['name' => $receiverName]) }}
                                @endif
                            </p>
                        </div>
                        @if ($rp)
                            <a href="{{ route('matrimony.profile.show', $rp->id) }}" class="inline-flex items-center justify-center rounded-md border border-indigo-200 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/30">{{ __('mediation.view_profile') }}</a>
                        @endif
                    </div>

                    @if ($receiverChoice || $declineReason || $declineReasonNote || $nextAction || $mr->response_feedback)
                        <div class="mt-4 rounded-md border border-emerald-100 bg-emerald-50/70 px-3 py-3 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/25 dark:text-emerald-100">
                            @if ($receiverChoice)
                                <p><span class="font-semibold">{{ __('mediation.response_choice_label') }}:</span> {{ $formatResponseChoice($receiverChoice) }}</p>
                            @endif
                            @if ($declineReason)
                                <p class="mt-1"><span class="font-semibold">{{ __('mediation.decline_reason_label') }}:</span> {{ $formatDeclineReason($declineReason) }}</p>
                            @endif
                            @if ($declineReasonNote)
                                <p class="mt-1"><span class="font-semibold">{{ __('mediation.decline_reason_note_label') }}:</span> {{ $declineReasonNote }}</p>
                            @endif
                            @if ($nextAction)
                                <p class="mt-1"><span class="font-semibold">{{ __('mediation.next_action_label') }}:</span> {{ $formatNextAction($nextAction) }}</p>
                            @endif
                            @if ($mr->response_feedback)
                                <p class="mt-1"><span class="font-semibold">{{ __('mediation.feedback_label') }}:</span> {{ $mr->response_feedback }}</p>
                            @endif
                            @if ($mr->responded_at)
                                <p class="mt-2 text-emerald-800/75 dark:text-emerald-100/70">{{ __('mediation.responded_at_label', ['time' => $mr->responded_at->timezone(config('app.timezone'))->format('M j, Y H:i')]) }}</p>
                            @endif
                        </div>
                    @endif
                    <p class="text-xs text-gray-500">{{ $mr->created_at->diffForHumans() }}</p>
                </div>
            @empty
                <div class="space-y-1 text-gray-600 dark:text-gray-400">
                    <p>{{ __('mediation.empty_outgoing') }}</p>
                    <p class="text-sm">{{ __('mediation.empty_outgoing_help') }}</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
