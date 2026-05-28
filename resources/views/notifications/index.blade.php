@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto px-4 pb-28 pt-6 sm:py-8">
    <h1 class="mb-4 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('notifications.page_title') }}</h1>

    @if (session('success'))
        <p class="text-green-600 mb-4">{{ session('success') }}</p>
    @endif

    @if ($unreadNotifications->isNotEmpty())
        <form method="POST" action="{{ route('notifications.mark-all-read') }}" class="mb-4">
            @csrf
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer;">{{ __('notifications.mark_all_read') }}</button>
        </form>
    @endif

    @forelse ($notifications as $n)
        <div class="mb-3 overflow-hidden rounded-xl border shadow-sm {{ $n->read_at ? 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/60' : 'border-gray-200 border-l-4 border-l-indigo-500 bg-white dark:border-gray-700 dark:bg-gray-800' }}">
            <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1">
                    @php
                        $data = is_array($n->data) ? $n->data : [];
                        $message = \App\Support\NotificationLocalization::displayMessage(
                            $data,
                            \App\Support\NotificationLocalization::preferredLocaleForUser($request->user()),
                        );
                        $revealed = ($data['revealed'] ?? true) !== false;
                        $notificationType = (string) ($data['type'] ?? '');
                        $teaserPayload = $localizedTeasers[$n->id] ?? ($data['teaser'] ?? null);
                        $actorProfileId = null;
                        foreach (['viewer_profile_id','sender_profile_id','accepter_profile_id','rejecter_profile_id','receiver_profile_id'] as $k) {
                            $v = (int) ($data[$k] ?? 0);
                            if ($revealed && $v > 0) { $actorProfileId = $v; break; }
                        }
                        $actor = ($actorProfileId && isset($actorProfiles)) ? ($actorProfiles[$actorProfileId] ?? null) : null;
                        $actorHref = $actor ? route('matrimony.profile.show', $actor->id) : null;
                        $hasApprovedPhoto = $actor && $actor->profile_photo && $actor->photo_approved !== false;
                        $photoSrc = $actor ? ($hasApprovedPhoto ? app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($actor->profile_photo) : $actor->profile_photo_url) : null;
                    @endphp

                    @if ($actor && $actorHref)
                        <div class="flex items-center gap-3 mb-2">
                            <a href="{{ $actorHref }}" class="shrink-0" aria-label="Open profile">
                                <img
                                    src="{{ $photoSrc }}"
                                    class="w-12 h-12 rounded-full object-cover border bg-white"
                                    alt=""
                                    loading="lazy"
                                />
                            </a>
                            <div class="min-w-0">
                                <a href="{{ $actorHref }}" class="block font-semibold text-indigo-700 hover:underline truncate">
                                    {{ $actor->full_name ?? ($data['sender_name'] ?? __('notifications.view_profile')) }}
                                </a>
                            </div>
                        </div>
                    @endif

                    @if (($n->data['type'] ?? '') === 'chat_message_locked')
                        <a href="{{ route('plans.index') }}" class="block font-medium {{ $n->read_at ? 'text-gray-700' : 'text-gray-900' }}">
                            {{ $message }}
                        </a>
                    @elseif (($n->data['type'] ?? '') === 'chat_message' && ! empty($n->data['conversation_id']) && (($n->data['revealed'] ?? true) !== false))
                        <a
                            href="{{ route('chat.show', ['conversation' => $n->data['conversation_id']]) }}"
                            data-open-chat-conversation="{{ (int) $n->data['conversation_id'] }}"
                            data-notification-id="{{ $n->id }}"
                            data-chat-conversation="{{ $n->data['conversation_id'] }}"
                            class="block font-medium {{ $n->read_at ? 'text-gray-700' : 'text-gray-900' }}"
                        >
                            <span class="font-semibold">{{ $n->data['sender_name'] ?? __('notifications.someone') }}</span>
                            <span class="text-gray-700">{{ __('notifications.sent_you_message') }}</span>
                            @if (! empty($n->data['message_preview']))
                                <span class="mt-1 block text-sm text-gray-600">{{ $n->data['message_preview'] }}</span>
                            @endif
                        </a>
                    @elseif (in_array(($n->data['type'] ?? ''), ['mediation_request_received', 'mediation_request_response'], true))
                        <a href="{{ route('mediation-inbox.index') }}" class="block font-medium {{ $n->read_at ? 'text-gray-700' : 'text-gray-900' }}">
                            {{ $message }}
                        </a>
                    @elseif ($notificationType === 'contact_request_received')
                        <a href="{{ route('contact-inbox.index') }}" class="block font-medium {{ $n->read_at ? 'text-gray-700 dark:text-gray-200' : 'text-gray-900 dark:text-gray-100' }}">
                            {{ $message }}
                        </a>
                    @elseif ($notificationType === 'image_approved')
                        <a href="{{ route('matrimony.profile.upload-photo') }}" class="block font-medium {{ $n->read_at ? 'text-gray-700 dark:text-gray-200' : 'text-gray-900 dark:text-gray-100' }}">
                            {{ $message }}
                        </a>
                    @elseif (str_starts_with($notificationType, 'referral_'))
                        <a href="{{ $notificationType === 'referral_reward_pending' ? route('plans.index') : route('referrals.index') }}" class="block font-medium {{ $n->read_at ? 'text-gray-700 dark:text-gray-200' : 'text-gray-900 dark:text-gray-100' }}">
                            {{ $message }}
                        </a>
                    @elseif (in_array($notificationType, ['interest_sent', 'profile_viewed'], true) && is_array($teaserPayload) && (($data['revealed'] ?? true) === false))
                        <div class="space-y-2">
                            @include('who-viewed.partials.viewer-row-teaser', [
                                'teaser' => $teaserPayload,
                                'plansUrl' => $data['teaser_plans_url'] ?? route('plans.index'),
                                'cardLayout' => 'vertical',
                                'hideTeaserCtaColumn' => true,
                            ])
                        </div>
                    @else
                        <a
                            href="{{ $actorHref ?: route('notifications.show', $n->id) }}"
                            class="block break-words font-medium leading-relaxed {{ $n->read_at ? 'text-gray-700 dark:text-gray-200' : 'text-gray-900 dark:text-gray-100' }}"
                        >
                            {{ $message }}
                        </a>
                    @endif
                    <p class="text-sm text-gray-500 mt-1">{{ $n->created_at->diffForHumans() }}</p>
                </div>
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 sm:shrink-0 sm:justify-end">
                    @if (($n->data['type'] ?? '') === 'chat_message_locked')
                        <a href="{{ route('plans.index') }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('interests.upgrade_for_more_reveals') }}</a>
                    @elseif (($n->data['type'] ?? '') === 'chat_message' && ! empty($n->data['conversation_id']) && (($n->data['revealed'] ?? true) !== false))
                        <a
                            href="{{ route('chat.show', ['conversation' => $n->data['conversation_id']]) }}"
                            data-open-chat-conversation="{{ (int) $n->data['conversation_id'] }}"
                            data-notification-id="{{ $n->id }}"
                            data-chat-conversation="{{ $n->data['conversation_id'] }}"
                            class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                        >{{ __('notifications.open_chat') }}</a>
                    @elseif (in_array(($n->data['type'] ?? ''), ['mediation_request_received', 'mediation_request_response'], true))
                        <a href="{{ route('mediation-inbox.index') }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('notifications.open') }}</a>
                    @elseif ($notificationType === 'contact_request_received')
                        <a href="{{ route('contact-inbox.index') }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('notifications.open_contact_inbox') }}</a>
                    @elseif ($notificationType === 'image_approved')
                        <a href="{{ route('matrimony.profile.upload-photo') }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('notifications.view_profile') }}</a>
                    @elseif (str_starts_with($notificationType, 'referral_'))
                        <a href="{{ $notificationType === 'referral_reward_pending' ? route('plans.index') : route('referrals.index') }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ $notificationType === 'referral_reward_pending' ? __('referrals.view_plans_claim') : __('referrals.dashboard_card_link') }}
                        </a>
                    @elseif (in_array($notificationType, ['interest_sent', 'profile_viewed'], true) && is_array($teaserPayload) && (($n->data['revealed'] ?? true) === false))
                        @php
                            $contextLabel = $notificationType === 'interest_sent'
                                ? __('notifications.teaser_open_received_interests')
                                : __('notifications.teaser_open_who_viewed');
                        @endphp
                        <a href="{{ $n->data['teaser_plans_url'] ?? route('plans.index') }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('interests.upgrade_for_more_reveals') }}</a>
                        <a href="{{ $n->data['teaser_context_url'] ?? route('notifications.index') }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $contextLabel }}</a>
                    @else
                        <a href="{{ $actorHref ?: route('notifications.show', $n->id) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ $actorHref ? __('notifications.open_profile') : __('notifications.open') }}
                        </a>
                    @endif
                    @if (!$n->read_at)
                        <form method="POST" action="{{ route('notifications.mark-read', $n->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-left text-sm font-medium text-gray-500 hover:underline dark:text-gray-400">{{ __('notifications.mark_read') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="text-gray-500">{{ __('notifications.none') }}</p>
    @endforelse

    <div class="mt-4">{{ $notifications->links() }}</div>
</div>

@auth
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (!csrf) return;

    function markRead(id) {
        return fetch(`{{ url('/notifications') }}/${id}/mark-read`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        }).catch(() => {});
    }

    document.addEventListener('click', function (e) {
        const a = e.target.closest('a[data-notification-id][data-chat-conversation]');
        if (!a) return;
        e.preventDefault();
        const id = a.getAttribute('data-notification-id');
        const href = a.getAttribute('href');
        const conversationId = a.getAttribute('data-chat-conversation');
        markRead(id).finally(() => {
            if (typeof window.openChatConversationPopup === 'function'
                && typeof window.shouldOpenChatPopupForViewport === 'function'
                && window.shouldOpenChatPopupForViewport()
            ) {
                window.openChatConversationPopup(conversationId, href);
                return;
            }
            window.location.href = href;
        });
    });
})();
</script>
@endauth
@endsection
