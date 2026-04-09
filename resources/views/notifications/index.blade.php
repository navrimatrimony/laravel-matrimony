@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto py-8 px-4">
    <h1 class="text-2xl font-bold mb-4">Notifications</h1>

    @if (session('success'))
        <p class="text-green-600 mb-4">{{ session('success') }}</p>
    @endif

    @if ($unreadNotifications->isNotEmpty())
        <form method="POST" action="{{ route('notifications.mark-all-read') }}" class="mb-4">
            @csrf
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer;">Mark all as read</button>
        </form>
    @endif

    @forelse ($notifications as $n)
        <div class="border rounded-lg p-4 mb-3 {{ $n->read_at ? 'bg-gray-50' : 'bg-white border-l-4 border-l-indigo-500' }}">
            <div class="flex justify-between items-start gap-2">
                <div class="min-w-0">
                    @php
                        $data = is_array($n->data) ? $n->data : [];
                        $revealed = ($data['revealed'] ?? true) !== false;
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
                                    {{ $actor->full_name ?? ($data['sender_name'] ?? 'View profile') }}
                                </a>
                            </div>
                        </div>
                    @endif

                    @if (($n->data['type'] ?? '') === 'chat_message' && ! empty($n->data['conversation_id']))
                        <a
                            href="{{ route('chat.show', ['conversation' => $n->data['conversation_id']]) }}"
                            data-notification-id="{{ $n->id }}"
                            data-chat-conversation="{{ $n->data['conversation_id'] }}"
                            class="block font-medium {{ $n->read_at ? 'text-gray-700' : 'text-gray-900' }}"
                        >
                            <span class="font-semibold">{{ $n->data['sender_name'] ?? 'Someone' }}</span>
                            <span class="text-gray-700">sent you a message</span>
                            @if (! empty($n->data['message_preview']))
                                <span class="mt-1 block text-sm text-gray-600">{{ $n->data['message_preview'] }}</span>
                            @endif
                        </a>
                    @elseif (in_array(($n->data['type'] ?? ''), ['mediation_request_received', 'mediation_request_response'], true))
                        <a href="{{ route('mediation-inbox.index') }}" class="block font-medium {{ $n->read_at ? 'text-gray-700' : 'text-gray-900' }}">
                            {{ $n->data['message'] ?? 'Mediation' }}
                        </a>
                    @else
                        <a
                            href="{{ $actorHref ?: route('notifications.show', $n->id) }}"
                            class="block font-medium {{ $n->read_at ? 'text-gray-700' : 'text-gray-900' }}"
                        >
                            {{ $n->data['message'] ?? 'Notification' }}
                        </a>
                    @endif
                    <p class="text-sm text-gray-500 mt-1">{{ $n->created_at->diffForHumans() }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    @if (($n->data['type'] ?? '') === 'chat_message' && ! empty($n->data['conversation_id']))
                        <a
                            href="{{ route('chat.show', ['conversation' => $n->data['conversation_id']]) }}"
                            data-notification-id="{{ $n->id }}"
                            data-chat-conversation="{{ $n->data['conversation_id'] }}"
                            class="text-indigo-600 text-sm hover:underline"
                        >Open chat</a>
                    @elseif (in_array(($n->data['type'] ?? ''), ['mediation_request_received', 'mediation_request_response'], true))
                        <a href="{{ route('mediation-inbox.index') }}" class="text-indigo-600 text-sm hover:underline">Open</a>
                    @else
                        <a href="{{ $actorHref ?: route('notifications.show', $n->id) }}" class="text-indigo-600 text-sm hover:underline">
                            {{ $actorHref ? 'Open profile' : 'Open' }}
                        </a>
                    @endif
                    @if (!$n->read_at)
                        <form method="POST" action="{{ route('notifications.mark-read', $n->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-500 text-sm hover:underline">Mark read</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="text-gray-500">No notifications.</p>
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
        markRead(id).finally(() => {
            window.location.href = href;
        });
    });
})();
</script>
@endauth
@endsection
