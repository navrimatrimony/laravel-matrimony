@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-6">
    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0 flex items-center gap-3">
            <img src="{{ $other->profile_photo_url }}" alt="{{ $other->full_name ?: ('Profile #' . $other->id) }}" class="h-10 w-10 rounded-full object-cover ring-1 ring-black/10" />
            <div class="min-w-0">
            <a href="{{ route('chat.index', ['filter' => request()->query('filter', 'all')]) }}" class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">← Back</a>
            <h1 class="mt-1 truncate text-xl font-bold text-gray-900 dark:text-gray-100">
                {{ $other->full_name ?: ('Profile #' . $other->id) }}
            </h1>
            @if (!empty($showcaseTag))
                <div class="mt-1 flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700 ring-1 ring-indigo-200 dark:bg-indigo-950/30 dark:text-indigo-200 dark:ring-indigo-800/60">
                        AI Assisted Replies
                    </span>
                    <span id="showcase-presence" class="hidden text-xs text-gray-500 dark:text-gray-400">Online</span>
                    <span id="showcase-typing" class="hidden text-xs font-semibold text-gray-600 dark:text-gray-300">typing…</span>
                </div>
            @endif
            </div>
        </div>
        <a href="{{ route('matrimony.profile.show', ['matrimony_profile_id' => $other->id]) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">View profile</a>
    </div>

        @if (session('error'))
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200">
            {{ $errors->first() }}
        </div>
    @endif

    @php
        $gateCodesForUpgrade = ['reply_gate_limit', 'reply_gate_cooldown', 'daily_limit', 'weekly_limit', 'monthly_limit'];
        $policyCode = (string) ($canSendDecision->code ?? '');
        $showChatUpgradeCta = !($canSendDecision->allowed ?? false) && in_array($policyCode, $gateCodesForUpgrade, true);
        $upgradeUrl = trim((string) config('communication.chat_upgrade_cta_url', ''));
        if ($upgradeUrl === '') {
            $upgradeUrl = route('dashboard');
        }
    @endphp

    @if (!($canSendDecision->allowed ?? false))
        <div class="mt-4 overflow-hidden rounded-2xl border border-amber-200/90 bg-gradient-to-br from-amber-50 via-white to-amber-50/80 shadow-sm dark:border-amber-900/50 dark:from-amber-950/40 dark:via-gray-900 dark:to-amber-950/30">
            <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                <div class="min-w-0 flex gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800 ring-1 ring-amber-200/80 dark:bg-amber-900/50 dark:text-amber-100 dark:ring-amber-800/60" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-amber-950 dark:text-amber-100">संदेश पाठवता येणार नाहीत (मर्यादा)</p>
                        <p class="mt-1 text-sm leading-relaxed text-amber-950/90 dark:text-amber-50/95">{{ $canSendDecision->humanMessage }}</p>
                        @if ($canSendDecision->lockedUntil)
                            <p class="mt-2 text-xs font-medium text-amber-900/80 dark:text-amber-200/80">पुन्हा पाठवू शकता: <span class="tabular-nums">{{ \Carbon\Carbon::parse($canSendDecision->lockedUntil)->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span></p>
                        @endif
                    </div>
                </div>
                @if ($showChatUpgradeCta)
                    <div class="flex shrink-0 flex-col gap-2 sm:max-w-[14rem] sm:text-right">
                        <p class="text-xs leading-snug text-amber-950/85 dark:text-amber-100/85">Premium सदस्यांना जास्त संदेश, फोटो शेअरिंग आणि इतर फायदे मिळतात.</p>
                        <a href="{{ $upgradeUrl }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm ring-1 ring-indigo-500/30 transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:ring-offset-gray-900">
                            Premium पहा / अपग्रेड
                        </a>
                        <span class="text-[11px] text-amber-900/65 dark:text-amber-200/65">किंवा समोरील व्यक्तीचा reply येईपर्यंत प्रतीक्षा करा.</span>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="mt-4">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full {{ ($conversation->status ?? '') === 'active' ? 'bg-emerald-500' : 'bg-gray-400' }}" aria-hidden="true"></span>
                <span class="font-semibold text-gray-700 dark:text-gray-200">Status:</span> {{ $conversation->status }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="font-semibold text-gray-700 dark:text-gray-200">Last message:</span> {{ $conversation->last_message_at?->diffForHumans() ?? '—' }}
            </span>
        </div>
    </div>

    <div class="mt-6">
        <section>
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 flex flex-col h-[70vh] sm:h-[72vh]">
                <div id="chat-scroll" class="flex-1 overflow-y-auto px-4 py-4 space-y-3 min-w-0">
                    @if ($messages->isEmpty())
                        <div class="flex h-full items-center justify-center px-6 text-center">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">हा chat सुरू करा. तुमचा पहिला संदेश पाठवा.</p>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Message history इथे दिसेल.</p>
                            </div>
                        </div>
                    @else
                        @foreach ($messages as $m)
                            @include('chat.partials.message-bubble', [
                                'message' => $m,
                                'isMine' => ((int) $m->sender_profile_id === (int) $me->id),
                                'senderPhotoUrl' => $m->senderProfile?->profile_photo_url,
                                'viewerProfileId' => (int) ($viewerProfileId ?? $me->id),
                            ])
                        @endforeach
                    @endif
                </div>
                <div class="px-4 pb-2 text-xs text-gray-500 dark:text-gray-400">
                    @if (method_exists($paginator, 'links'))
                        {{ $paginator->links() }}
                    @endif
                </div>

                <div class="relative min-h-[7.5rem] border-t border-gray-200 px-4 py-4 dark:border-gray-700 bg-white/95 dark:bg-gray-900/95 sticky bottom-0">
                    {{-- Image composer (hidden input; UI shown only after file selected) --}}
                    <form id="image-form" method="POST" action="{{ route('chat.messages.image', ['conversation' => $conversation->id]) }}" enctype="multipart/form-data" class="hidden">
                        @csrf
                        <input id="image-input" type="file" name="image" accept="image/*" />
                        <input id="image-caption" type="text" name="caption" />
                    </form>

                    {{-- Image preview row (appears only when a file is picked) --}}
                    <div id="image-preview-row" class="hidden mb-3 rounded-2xl border border-gray-200 bg-white px-3 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                        <div class="flex items-start gap-3">
                            <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-gray-100 ring-1 ring-black/5 dark:bg-gray-900">
                                <img id="image-preview-img" src="" alt="Selected image preview" class="h-full w-full object-cover" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Photo ready</p>
                                    <button type="button" id="image-cancel" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-900/60 dark:hover:text-white">
                                        Cancel
                                    </button>
                                </div>
                                <input id="image-caption-ui" type="text" placeholder="Add a caption (optional)" class="mt-2 w-full min-w-0 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" />
                                <div class="mt-2 flex items-center justify-end gap-2">
                                    <button type="button" id="image-send" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                                        Send photo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Main composer (single row, WhatsApp-style) --}}
                    <div class="flex items-end gap-2">
                        <button
                            type="button"
                            id="attach-btn"
                            class="relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:bg-gray-900 disabled:cursor-not-allowed disabled:opacity-70"
                            title="{{ ($imagePolicy['allowed'] ?? false) ? 'Send photo' : 'Send photo (Premium)' }}"
                            @disabled(!($canSendDecision->allowed ?? false))
                        >
                            {{-- paperclip --}}
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M8 12.5l7.2-7.2a3 3 0 114.2 4.2l-8.5 8.5a5 5 0 01-7.1-7.1l8.1-8.1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            @if (!($imagePolicy['allowed'] ?? true))
                                <span class="absolute -right-1 -top-1 rounded-full bg-amber-400 px-1.5 py-0.5 text-[10px] font-bold leading-none text-gray-900 shadow-sm">PRO</span>
                            @endif
                        </button>

                        <form method="POST" action="{{ route('chat.messages.text', ['conversation' => $conversation->id]) }}" class="flex flex-1 items-end gap-2" onsubmit="const b=this.querySelector('button[type=submit]'); if(b){ b.disabled=true; b.setAttribute('aria-busy','true'); }">
                            @csrf
                            <textarea name="body_text" rows="2" placeholder="Type a message..." class="w-full min-w-0 resize-none rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 disabled:cursor-not-allowed disabled:opacity-70" @disabled(!($canSendDecision->allowed ?? false))></textarea>
                            <button type="submit" class="shrink-0 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" @disabled(!($canSendDecision->allowed ?? false))>
                                Send
                            </button>
                        </form>
                    </div>

                    @if ($showChatUpgradeCta && !($canSendDecision->allowed ?? false))
                        <div class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-b-2xl bg-white/85 px-3 py-4 text-center shadow-[inset_0_1px_0_0_rgba(0,0,0,0.04)] backdrop-blur-[2px] dark:bg-gray-950/90" role="region" aria-label="संदेश मर्यादा">
                            <a href="{{ $upgradeUrl }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-md ring-1 ring-indigo-500/25 transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:ring-offset-gray-950">
                                Premium पहा / अपग्रेड
                            </a>
                            <p class="max-w-sm text-[11px] leading-relaxed text-gray-600 dark:text-gray-300">मर्यादा संपेपर्यंत प्रतीक्षा किंवा Premium सदस्य होऊन जास्त संदेश व सुविधा वापरा.</p>
                        </div>
                    @endif

                    {{-- Premium upsell (free users only) --}}
                    <div id="premium-modal" class="hidden fixed inset-0 z-50">
                        <div id="premium-backdrop" class="absolute inset-0 bg-black/50"></div>
                        <div class="absolute inset-x-0 bottom-0 rounded-t-3xl border border-gray-200 bg-white p-5 shadow-2xl dark:border-gray-700 dark:bg-gray-950">
                            <div class="mx-auto max-w-lg">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Send photos in chat</p>
                                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">This is a Premium feature. Upgrade to unlock photo sharing.</p>
                                    </div>
                                    <button type="button" id="premium-close" class="rounded-xl px-2 py-1 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-900/60 dark:hover:text-white">✕</button>
                                </div>
                                <div class="mt-4 grid gap-2 text-sm text-gray-800 dark:text-gray-200">
                                    <div class="flex items-start gap-2"><span class="mt-0.5 text-amber-500">✓</span><span>Share biodata / family photos to build trust faster</span></div>
                                    <div class="flex items-start gap-2"><span class="mt-0.5 text-amber-500">✓</span><span>Instant unlock after upgrade</span></div>
                                </div>
                                <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                                    <a href="{{ $upgradeUrl }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Upgrade to Premium</a>
                                    <button type="button" id="premium-later" class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:bg-gray-900">Not now</button>
                                </div>
                                <p class="mt-3 text-[11px] text-gray-500 dark:text-gray-400">Secure payment • Instant unlock</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
(function () {
    const scroller = document.getElementById('chat-scroll');
    if (!scroller) return;

    const canSend = {{ ($canSendDecision->allowed ?? false) ? 'true' : 'false' }};
    const imageAllowed = {{ ($imagePolicy['allowed'] ?? false) ? 'true' : 'false' }};

    const attachBtn = document.getElementById('attach-btn');
    const imageInput = document.getElementById('image-input');
    const imageForm = document.getElementById('image-form');
    const previewRow = document.getElementById('image-preview-row');
    const previewImg = document.getElementById('image-preview-img');
    const captionUi = document.getElementById('image-caption-ui');
    const captionHidden = document.getElementById('image-caption');
    const imageSend = document.getElementById('image-send');
    const imageCancel = document.getElementById('image-cancel');

    const premiumModal = document.getElementById('premium-modal');
    const premiumBackdrop = document.getElementById('premium-backdrop');
    const premiumClose = document.getElementById('premium-close');
    const premiumLater = document.getElementById('premium-later');

    function openPremium() {
        if (!premiumModal) return;
        premiumModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }
    function closePremium() {
        if (!premiumModal) return;
        premiumModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
    if (premiumBackdrop) premiumBackdrop.addEventListener('click', closePremium);
    if (premiumClose) premiumClose.addEventListener('click', closePremium);
    if (premiumLater) premiumLater.addEventListener('click', closePremium);

    function clearSelectedImage() {
        if (previewRow) previewRow.classList.add('hidden');
        if (previewImg) previewImg.src = '';
        if (captionUi) captionUi.value = '';
        if (captionHidden) captionHidden.value = '';
        if (imageInput) imageInput.value = '';
    }
    if (imageCancel) imageCancel.addEventListener('click', clearSelectedImage);

    if (attachBtn) {
        attachBtn.addEventListener('click', () => {
            if (!canSend) return;
            if (!imageAllowed) {
                openPremium();
                return;
            }
            if (imageInput) imageInput.click();
        });
    }

    if (imageInput) {
        imageInput.addEventListener('change', () => {
            const f = imageInput.files && imageInput.files[0] ? imageInput.files[0] : null;
            if (!f) {
                clearSelectedImage();
                return;
            }
            if (previewImg) {
                try {
                    previewImg.src = URL.createObjectURL(f);
                } catch (e) {
                    previewImg.src = '';
                }
            }
            if (previewRow) previewRow.classList.remove('hidden');
        });
    }

    if (imageSend) {
        imageSend.addEventListener('click', () => {
            if (!imageAllowed || !canSend) return;
            if (!imageForm || !imageInput || !imageInput.files || !imageInput.files[0]) return;
            if (captionHidden && captionUi) captionHidden.value = (captionUi.value || '').trim();
            imageForm.submit();
        });
    }

    let lastId = {{ (int) ($messages->max('id') ?? 0) }};
    let hadAny = lastId > 0;
    let inFlight = false;

    function atBottom() {
        return (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight) < 80;
    }

    function toast(text) {
        const el = document.createElement('div');
        el.className = 'fixed bottom-6 right-6 z-50 rounded-xl bg-gray-900/90 px-4 py-3 text-sm font-semibold text-white shadow-lg';
        el.textContent = text;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 2400);
    }

    function scrollToBottom() {
        scroller.scrollTop = scroller.scrollHeight;
    }

    // Start at bottom on load.
    scrollToBottom();

    async function poll() {
        if (inFlight) return;
        inFlight = true;
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('since_id', String(lastId));
            const res = await fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            const html = data.html || [];
            if (data.showcase && typeof data.showcase === 'object') {
                const online = !!data.showcase.online;
                const typing = !!data.showcase.typing;
                const p = document.getElementById('showcase-presence');
                const t = document.getElementById('showcase-typing');
                if (p) {
                    p.textContent = online ? 'Online' : 'Offline';
                    p.classList.toggle('hidden', false);
                    p.classList.toggle('text-emerald-600', online);
                    p.classList.toggle('dark:text-emerald-400', online);
                }
                if (t) {
                    t.classList.toggle('hidden', !typing);
                }
            }
            if (html.length > 0) {
                const wasBottom = atBottom();
                const ids = Array.isArray(data.message_ids) ? data.message_ids : [];
                for (let i = 0; i < html.length; i++) {
                    const chunk = html[i];
                    const id = ids[i];
                    if (id && scroller.querySelector('[data-message-id="' + String(id) + '"]')) {
                        continue;
                    }
                    const wrap = document.createElement('div');
                    wrap.innerHTML = chunk;
                    const node = wrap.firstElementChild;
                    if (node) {
                        scroller.appendChild(node);
                    }
                }
                if (typeof data.last_id === 'number' && data.last_id > lastId) {
                    lastId = data.last_id;
                }
                if (wasBottom) {
                    scrollToBottom();
                } else {
                    toast('New message');
                }
            } else if (!hadAny) {
                // no-op
            }
        } catch (e) {
            // silent
        } finally {
            inFlight = false;
        }
    }

    setInterval(poll, 5000);

    // Refresh navbar chat badge once after open/read.
    setTimeout(() => {
        fetch(`{{ route('chat.index') }}?unread_only=1`, {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
        }).then(r => r.json()).then(data => {
            const count = data.count || 0;
            const badge = document.getElementById('chat-badge');
            const badgeMobile = document.getElementById('chat-badge-mobile');
            const displayCount = count > 99 ? '99+' : count;
            if (badge) {
                badge.textContent = displayCount;
                if (count > 0) badge.classList.remove('hidden'); else badge.classList.add('hidden');
            }
            if (badgeMobile) {
                badgeMobile.textContent = displayCount;
                badgeMobile.style.display = count > 0 ? 'inline-flex' : 'none';
            }
        }).catch(() => {});
    }, 700);
})();
</script>
@endsection

