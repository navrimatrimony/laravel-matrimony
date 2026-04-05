{{-- Primary profile actions (single include — top summary card on public profile). --}}
@php
    $contactAccess = $contactAccess ?? ['show_contact_request_rail' => true];
@endphp
@if (auth()->check() && !$isOwnProfile)
    @if (session('success'))
        <p class="mb-3 text-center text-sm text-emerald-700 dark:text-emerald-300">{{ session('success') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-3 text-center text-sm text-red-600 dark:text-red-400">{{ session('error') }}</p>
    @endif

    <div class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:justify-center sm:gap-3">
        @if ($interestAlreadySent)
            <button type="button" disabled class="w-full min-h-[2.75rem] cursor-not-allowed rounded-xl bg-stone-300 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm dark:bg-gray-600 sm:w-auto sm:min-w-[10rem]">
                {{ __('Interest Sent') }}
            </button>
        @else
            <form method="POST" action="{{ route('interests.send', $profile) }}" class="w-full sm:w-auto">
                @csrf
                <button type="submit" class="w-full min-h-[2.75rem] rounded-xl bg-rose-700 px-5 py-2.5 text-center text-sm font-semibold text-white shadow-md shadow-rose-900/15 transition hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 sm:min-w-[11rem]">
                    {{ __('Send Interest') }}
                </button>
            </form>
        @endif

        {{-- Day-32: Request Contact (button states + modal) --}}
        @if (!$contactRequestDisabled && $contactRequestState !== null && ($contactAccess['show_contact_request_rail'] ?? true))
            @php
                $crState = $contactRequestState['state'] ?? 'none';
                $crRequest = $contactRequestState['request'] ?? null;
                $crGrant = $contactRequestState['grant'] ?? null;
                $cooldownEndsAt = $contactRequestState['cooldown_ends_at'] ?? null;
                $reasons = config('communication.request_reasons', []);
            @endphp
            <div class="w-full sm:w-auto">
                @if ($crState === 'none' || ($crState === 'expired' && !$cooldownEndsAt) || $crState === 'cancelled')
                    @if ($canSendContactRequest)
                        <button type="button" @click="$root.openRequestModal = true" class="w-full min-h-[2.75rem] rounded-xl bg-emerald-600 px-5 py-2.5 text-center text-sm font-semibold text-white shadow-md shadow-emerald-900/15 transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 sm:min-w-[11rem]">
                            {{ __('Request Contact') }}
                        </button>
                    @else
                        <button type="button" disabled class="w-full min-h-[2.75rem] cursor-not-allowed rounded-xl bg-stone-300 px-4 py-2.5 text-center text-sm font-semibold text-white dark:bg-gray-600 sm:min-w-[11rem]">
                            {{ __('Request Contact') }}
                        </button>
                        <p class="mt-2 text-center text-xs text-stone-500 dark:text-stone-400">
                            {{ __('notifications.mutual_only') }}
                        </p>
                    @endif
                @elseif ($crState === 'pending')
                    <div class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-center">
                        <span class="inline-flex min-h-[2.75rem] items-center justify-center rounded-xl bg-amber-500 px-4 py-2 text-center text-sm font-semibold text-white shadow-sm">{{ __('Request Sent (Pending)') }}</span>
                        @if ($crRequest)
                            <form method="POST" action="{{ route('contact-requests.cancel', $crRequest) }}" class="w-full sm:w-auto">
                                @csrf
                                <button type="submit" class="w-full rounded-lg bg-stone-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-stone-700 sm:w-auto">{{ __('Cancel request') }}</button>
                            </form>
                        @endif
                    </div>
                @elseif ($crState === 'accepted' && $crGrant)
                    <a href="{{ route('matrimony.profile.show', $profile) }}#contact-reveal" class="inline-flex min-h-[2.75rem] w-full items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-center text-sm font-semibold text-white shadow-md hover:bg-emerald-800 sm:w-auto sm:min-w-[11rem]">{{ __('View Contact') }}</a>
                @elseif ($crState === 'rejected')
                    <span class="inline-flex min-h-[2.75rem] w-full items-center justify-center rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm sm:w-auto">{{ __('Request Rejected') }}</span>
                    @if ($cooldownEndsAt)
                        <span class="mt-2 block text-center text-sm text-stone-600 dark:text-stone-400">{{ __('Cooling period ends') }} {{ $cooldownEndsAt->format('M j, Y') }}</span>
                    @endif
                @elseif ($crState === 'expired')
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-center">
                        <span class="inline-flex min-h-[2.75rem] items-center justify-center rounded-xl bg-stone-400 px-4 py-2 text-sm font-semibold text-white">{{ __('Request Expired') }}</span>
                        @if (!$cooldownEndsAt)
                            <button type="button" @click="$root.openRequestModal = true" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">{{ __('Request again') }}</button>
                        @endif
                    </div>
                @elseif ($crState === 'revoked')
                    <span class="inline-flex min-h-[2.75rem] w-full items-center justify-center rounded-xl bg-stone-500 px-4 py-2 text-sm font-semibold text-white sm:w-auto">{{ __('Contact no longer available') }}</span>
                @endif

                {{-- Request Contact modal --}}
                <div x-show="$root.openRequestModal" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" style="display: none;" @click.self="$root.openRequestModal = false">
                    <div class="mx-4 w-full max-w-md rounded-lg bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop x-data="{ reason: '{{ old('reason', 'talk_to_family') }}' }">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Request Contact') }}</h3>
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">They will see your reason and chosen contact methods. They can approve or reject.</p>
                        <form method="POST" action="{{ route('contact-requests.store', $profile) }}">
                            @csrf
                            <div class="mb-4">
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Why are you requesting contact? <span class="text-red-500">*</span></label>
                                <select name="reason" required x-model="reason" class="w-full rounded-md border border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                    @foreach($reasons as $key => $label)
                                    <option value="{{ $key }}" {{ old('reason') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-4" x-show="reason === 'other'" x-cloak>
                                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Other (please specify)</label>
                                <textarea name="other_reason_text" rows="2" class="w-full rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" placeholder="Short reason">{{ old('other_reason_text') }}</textarea>
                            </div>
                            <div class="mb-4">
                                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Requested contact methods</label>
                                @foreach(['email' => 'Email', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp'] as $scope => $label)
                                <label class="mr-4 inline-flex items-center"><input type="checkbox" name="requested_scopes[]" value="{{ $scope }}" {{ in_array($scope, old('requested_scopes', [])) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600"> <span class="ml-1">{{ $label }}</span></label>
                                @endforeach
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="rounded-md bg-green-600 px-4 py-2 font-medium text-white">Send request</button>
                                <button type="button" @click="$root.openRequestModal = false" class="rounded-md bg-gray-500 px-4 py-2 font-medium text-white">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('blocks.store', $profile) }}" class="w-full sm:w-auto">
            @csrf
            <button type="submit" class="w-full min-h-[2.75rem] rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-center text-sm font-semibold text-stone-800 shadow-sm transition hover:bg-stone-50 dark:border-gray-600 dark:bg-gray-800 dark:text-stone-200 dark:hover:bg-gray-700 sm:min-w-[8rem]">
                {{ __('Block') }}
            </button>
        </form>

        @if ($inShortlist)
            <form method="POST" action="{{ route('shortlist.destroy', $profile) }}" class="w-full sm:w-auto">
                @csrf
                @method('DELETE')
                <button type="submit" class="w-full min-h-[2.75rem] rounded-xl bg-stone-500 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm hover:bg-stone-600 sm:min-w-[11rem]">
                    {{ __('Remove from shortlist') }}
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('shortlist.store', $profile) }}" class="w-full sm:w-auto">
                @csrf
                <button type="submit" class="w-full min-h-[2.75rem] rounded-xl bg-sky-600 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-md shadow-sky-900/20 hover:bg-sky-700 sm:min-w-[11rem]">
                    {{ __('Add to shortlist') }}
                </button>
            </form>
        @endif
    </div>

    {{-- Day-32: Contact reveal (only when viewer has active grant) --}}
    @if (!empty($contactGrantReveal))
        <div id="contact-reveal" class="mx-auto mt-4 max-w-xl rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
            <p class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('Contact (shared with you)') }}</p>
            @if (!empty($contactGrantReveal['email']))
                <p class="text-sm"><span class="text-gray-500">Email:</span> {{ $contactGrantReveal['email'] }}</p>
            @endif
            @if (!empty($contactGrantReveal['phone']))
                <p class="text-sm"><span class="text-gray-500">Phone:</span> {{ $contactGrantReveal['phone'] }}</p>
            @endif
            @if (!empty($contactGrantReveal['whatsapp']))
                <p class="text-sm"><span class="text-gray-500">WhatsApp:</span> {{ $contactGrantReveal['whatsapp'] }}</p>
            @endif
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Use respectfully. Report abuse if needed.</p>
        </div>
    @endif
@endif
