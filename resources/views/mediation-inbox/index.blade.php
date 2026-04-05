@extends('layouts.app')

@section('content')
<div class="py-8 max-w-4xl mx-auto px-4">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">{{ __('mediation.inbox_title') }}</h1>

    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 mb-4">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p class="text-red-600 dark:text-red-400 mb-4">{{ session('error') }}</p>
    @endif

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
                    $hint = data_get($mr->meta, 'matchmaking.compatibility_hint');
                @endphp
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-800">
                    <p class="font-semibold text-gray-900 dark:text-gray-100">{{ __('mediation.requested_by') }}: {{ $sp->full_name ?? $sender->name }}</p>
                    @if ($sp)
                        <a href="{{ route('matrimony.profile.show', $sp->id) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('mediation.view_profile') }}</a>
                    @endif
                    @if ($hint)
                        <p class="mt-3 rounded-md bg-stone-50 px-3 py-2 text-sm text-stone-700 dark:bg-stone-900/40 dark:text-stone-200">{{ $hint }}</p>
                    @endif
                    @if ($mr->subjectProfile && $mr->subjectProfile->id !== optional($sp)->id)
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('mediation.about_profile') }}:
                            <a href="{{ route('matrimony.profile.show', $mr->subjectProfile->id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $mr->subjectProfile->full_name ?? __('Profile') }}</a>
                        </p>
                    @endif
                    <p class="mt-1 text-xs text-gray-500">{{ $mr->created_at->diffForHumans() }}</p>

                    <form method="POST" action="{{ route('mediation-requests.respond', $mr) }}" class="mt-4 space-y-4 border-t border-stone-200 pt-4 dark:border-gray-600">
                        @csrf
                        <fieldset>
                            <legend class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('mediation.your_response') }}</legend>
                            <div class="flex flex-wrap gap-4 text-sm">
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="response" value="interested" class="rounded border-gray-300 dark:border-gray-600" required>
                                    <span>{{ __('mediation.interested') }}</span>
                                </label>
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="response" value="not_interested" class="rounded border-gray-300 dark:border-gray-600">
                                    <span>{{ __('mediation.not_interested') }}</span>
                                </label>
                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="response" value="need_more_info" class="rounded border-gray-300 dark:border-gray-600">
                                    <span>{{ __('mediation.need_more_info') }}</span>
                                </label>
                            </div>
                        </fieldset>
                        <div>
                            <label for="feedback-{{ $mr->id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('mediation.feedback_optional') }}</label>
                            <textarea id="feedback-{{ $mr->id }}" name="feedback" rows="3" maxlength="2000" class="w-full rounded-md border border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        </div>
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('mediation.submit_response') }}</button>
                    </form>
                </div>
            @empty
                <p class="text-gray-600 dark:text-gray-400">{{ __('mediation.empty_pending') }}</p>
            @endforelse
        </div>

        <div x-show="tab === 'history'" x-cloak class="space-y-4">
            @forelse ($responded as $mr)
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-800">
                    <p class="font-medium text-gray-900 dark:text-gray-100">
                        @if ($mr->status === \App\Models\MediationRequest::STATUS_INTERESTED)
                            {{ __('mediation.responded_interested') }}
                        @elseif ($mr->status === \App\Models\MediationRequest::STATUS_NEED_MORE_INFO)
                            {{ __('mediation.responded_need_more_info') }}
                        @else
                            {{ __('mediation.responded_not_interested') }}
                        @endif
                    </p>
                    @if ($mr->response_feedback)
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $mr->response_feedback }}</p>
                    @endif
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $mr->sender->matrimonyProfile->full_name ?? $mr->sender->name }} — {{ $mr->responded_at?->diffForHumans() }}</p>
                </div>
            @empty
                <p class="text-gray-600 dark:text-gray-400">{{ __('mediation.empty_history') }}</p>
            @endforelse
        </div>

        <div x-show="tab === 'outgoing'" x-cloak class="space-y-4">
            @forelse ($outgoing as $mr)
                @php
                    $recv = $mr->receiver;
                    $rp = $recv->matrimonyProfile ?? null;
                @endphp
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-800">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('To') }}: {{ $rp->full_name ?? $recv->name }}</p>
                    <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                        @if ($mr->status === \App\Models\MediationRequest::STATUS_PENDING)
                            {{ __('mediation.status_pending') }}
                        @elseif ($mr->status === \App\Models\MediationRequest::STATUS_INTERESTED)
                            {{ __('mediation.status_interested') }}
                        @elseif ($mr->status === \App\Models\MediationRequest::STATUS_NEED_MORE_INFO)
                            {{ __('mediation.status_need_more_info') }}
                        @else
                            {{ __('mediation.status_not_interested') }}
                        @endif
                    </p>
                    <p class="text-xs text-gray-500">{{ $mr->created_at->diffForHumans() }}</p>
                </div>
            @empty
                <p class="text-gray-600 dark:text-gray-400">{{ __('mediation.empty_outgoing') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
