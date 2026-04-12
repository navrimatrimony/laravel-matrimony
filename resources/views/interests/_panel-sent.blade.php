<div class="mb-6">
    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ __('interests.sent_interests') }}</h2>
</div>

@if ($sentInterests->isEmpty())
    <p class="text-gray-600 dark:text-gray-400">
        {{ ($statusFilter ?? 'all') !== 'all' ? __('interests.no_sent_for_filter') : __('interests.no_sent_interests') }}
    </p>
@else
    @foreach ($sentInterests as $interest)
        <div class="mb-4 flex flex-col gap-4 rounded-2xl border border-gray-200/90 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800/90 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('interests.to') }}: {{ $interest->receiverProfile->full_name ?? __('interests.profile_deleted') }}
                </p>
                @if ($interest->receiverProfile)
                    <p class="mt-2">
                        <a href="{{ route('matrimony.profile.show', $interest->receiverProfile->id) }}"
                           class="text-sm font-medium text-rose-600 hover:text-rose-800 dark:text-rose-400 dark:hover:text-rose-300">
                            {{ __('interests.view_matrimony_profile') }} →
                        </a>
                    </p>
                @endif
                <p class="mt-2 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('interests.status') }}:</span>
                    @if ($interest->status === 'pending')
                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ __('interests.pending') }}</span>
                    @elseif ($interest->status === 'accepted')
                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ __('interests.accepted') }}</span>
                    @elseif ($interest->status === 'rejected')
                        <span class="font-semibold text-red-600 dark:text-red-400">{{ __('interests.rejected') }}</span>
                    @endif
                </p>
                @if ($interest->status === 'pending')
                    <form method="POST" action="{{ route('interests.withdraw', $interest->id) }}" class="mt-3">
                        @csrf
                        <button type="submit"
                            class="text-sm font-medium text-red-600 hover:underline dark:text-red-400"
                            onclick="return confirm(@json(__('interests.confirm_withdraw_interest')))">
                            {{ __('interests.withdraw_interest') }}
                        </button>
                    </form>
                @endif
            </div>
            <div class="shrink-0 self-start sm:self-center">
                @if ($interest->receiverProfile && $interest->receiverProfile->profile_photo && $interest->receiverProfile->photo_approved !== false)
                    <img
                        src="{{ app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($interest->receiverProfile->profile_photo) }}"
                        alt=""
                        class="h-16 w-16 rounded-2xl object-cover ring-2 ring-gray-100 dark:ring-gray-600">
                @else
                    @php
                        $recGender = $interest->receiverProfile->gender?->key ?? null;
                        $recPlaceholder = $recGender === 'male'
                            ? asset('images/placeholders/male-profile.svg')
                            : ($recGender === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
                    @endphp
                    <img src="{{ $recPlaceholder }}" alt="" class="h-16 w-16 rounded-2xl object-cover ring-2 ring-gray-100 opacity-95 dark:ring-gray-600">
                @endif
            </div>
        </div>
    @endforeach
@endif
