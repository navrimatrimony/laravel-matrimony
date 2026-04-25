@auth
@if (is_array($chatDockData ?? null))
@php
    $chatDockInitialData = [
        'unread_count' => (int) ($chatDockData['unread_count'] ?? 0),
        'unread' => array_values($chatDockData['unread'] ?? []),
        'chats' => array_values($chatDockData['chats'] ?? []),
        'active' => array_values($chatDockData['active'] ?? []),
        'can_read_incoming' => (bool) ($chatDockData['can_read_incoming'] ?? false),
    ];
    $dockHasAlerts = ($chatDockInitialData['unread_count'] > 0) || count($chatDockInitialData['unread']) > 0;
@endphp

<div
    id="chatPanel"
    class="pointer-events-none fixed right-0 top-[7.5rem] z-[52] hidden transition-transform duration-300 ease-out lg:flex lg:flex-col lg:items-stretch"
>
<section
    id="chat-dock-root"
    class="pointer-events-auto flex h-auto max-h-[72vh] min-h-0 w-[17rem] flex-col border-l border-gray-200 bg-white shadow-2xl transition-transform duration-300 ease-out will-change-transform dark:border-gray-800 dark:bg-gray-900{{ $dockHasAlerts ? '' : ' translate-x-full' }}"
    role="complementary"
    aria-label="{{ __('chat_ui.dock_panel_title') }}"
    data-label-profile="{{ __('chat_ui.dock_profile_link') }}"
    data-label-open-chat="{{ __('chat_ui.dock_open_chat_link') }}"
    data-hint-alerts="{{ __('chat_ui.dock_hint_alerts') }}"
    data-hint-chats="{{ __('chat_ui.dock_hint_chats') }}"
    data-hint-active="{{ __('chat_ui.dock_hint_active') }}"
>
    <header class="border-b border-gray-200 bg-gradient-to-r from-red-500 to-rose-600 px-3 py-2.5 text-white dark:border-gray-800">
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-semibold text-rose-100">{{ __('chat_ui.dock_panel_title') }}</p>
            <button type="button" id="chat-dock-header-minimize" class="rounded px-1 text-sm font-bold text-white/80 hover:bg-white/20" aria-label="{{ __('chat_ui.dock_minimize_aria') }}">−</button>
        </div>
    </header>

    <div class="border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-950/40">
        <p id="chat-dock-tab-hint" class="text-[11px] leading-snug text-gray-600 dark:text-gray-400"></p>
    </div>

    <div class="overflow-y-auto bg-gray-50 p-2.5 dark:bg-gray-950 max-h-[48vh]">
        <div class="space-y-2 chat-dock-tab-content" data-tab-content="alerts"></div>
        <div class="hidden space-y-2 chat-dock-tab-content" data-tab-content="chats"></div>
        <div class="hidden space-y-2 chat-dock-tab-content" data-tab-content="active"></div>
    </div>

    <div class="border-t border-gray-200 bg-white px-2.5 py-2 dark:border-gray-800 dark:bg-gray-900">
        <div class="grid grid-cols-3 gap-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-800">
            <button type="button" class="chat-dock-tab-btn rounded-lg px-2 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-100" data-tab="alerts">
                Alerts <span id="chat-tab-alerts-count" class="ml-1 text-[10px] font-bold text-green-600">0</span>
            </button>
            <button type="button" class="chat-dock-tab-btn rounded-lg px-2 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-100" data-tab="chats">
                Chats <span id="chat-dock-badge" class="ml-1 text-[10px] font-bold text-red-600 hidden">0</span>
            </button>
            <button type="button" class="chat-dock-tab-btn rounded-lg px-2 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-100" data-tab="active">
                Active <span id="chat-tab-active-count" class="ml-1 text-[10px] text-gray-500">0</span>
            </button>
        </div>
    </div>
</section>
</div>

<div id="chat-popout-layer" class="pointer-events-none fixed bottom-4 right-[18rem] z-50 hidden max-w-[calc(100vw-19rem)] items-end gap-3"></div>
<div id="chat-minimized-chipbar" class="pointer-events-none fixed bottom-3 right-[18rem] z-50 hidden items-center gap-2"></div>
<script type="application/json" id="chat-dock-initial-data">{!! json_encode($chatDockInitialData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

<script>
(function () {
    const root = document.getElementById('chat-dock-root');
    if (!root) return;
    const initialDataEl = document.getElementById('chat-dock-initial-data');
    const tabButtons = root.querySelectorAll('.chat-dock-tab-btn');
    const tabContents = root.querySelectorAll('.chat-dock-tab-content');
    const tabHint = document.getElementById('chat-dock-tab-hint');
    const tabAlertsCount = document.getElementById('chat-tab-alerts-count');
    const tabActiveCount = document.getElementById('chat-tab-active-count');
    const dockBadge = document.getElementById('chat-dock-badge');
    const popoutLayer = document.getElementById('chat-popout-layer');
    const chipBar = document.getElementById('chat-minimized-chipbar');
    /** #chatTab is rendered later (help partial); resolve at use-time or use delegation — never null at parse time. */
    function getChatTab() {
        return document.getElementById('chatTab');
    }
    const headerMinBtn = document.getElementById('chat-dock-header-minimize');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    /** Dock width (rem); keep in sync with #chat-dock-root Tailwind w-[…rem] */
    const DOCK_WIDTH_REM = 17;
    const POPOUT_GAP_REM = 1;
    const POPOUT_STACK_STEP_REM = 22.5;

    const LS_COLLAPSED_DAY_KEY = 'chat_dock_collapsed_day_v1';

    function localCalendarYmd() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    /** User tapped minimize today — suppress auto-expand from alerts until tomorrow */
    function userCollapsedDockToday() {
        try {
            return localStorage.getItem(LS_COLLAPSED_DAY_KEY) === localCalendarYmd();
        } catch (_e) {
            return false;
        }
    }

    function rememberCollapsedDockToday() {
        try {
            localStorage.setItem(LS_COLLAPSED_DAY_KEY, localCalendarYmd());
        } catch (_e) {}
    }

    function popoutRightOffsetRem(stackIndex) {
        return DOCK_WIDTH_REM + POPOUT_GAP_REM + (stackIndex * POPOUT_STACK_STEP_REM);
    }

    const openPopouts = new Map();
    const minimizedPopouts = new Map();
    let currentTab = 'alerts';
    let dockData = { unread_count: 0, unread: [], chats: [], active: [], can_read_incoming: false };
    let dockLastHadUnreadAlerts = false;

    if (initialDataEl) {
        try {
            const parsed = JSON.parse(initialDataEl.textContent || '{}');
            if (parsed && typeof parsed === 'object') {
                dockData = {
                    unread_count: Number(parsed.unread_count || 0),
                    unread: Array.isArray(parsed.unread) ? parsed.unread : [],
                    chats: Array.isArray(parsed.chats) ? parsed.chats : [],
                    active: Array.isArray(parsed.active) ? parsed.active : [],
                    can_read_incoming: !!parsed.can_read_incoming,
                };
            }
        } catch (_e) {}
    }

    function hasUnreadAlerts() {
        return Number(dockData.unread_count || 0) > 0 || (dockData.unread || []).length > 0;
    }

    function refreshExpandHandleVisibility() {
        const tab = getChatTab();
        if (!tab || !root) return;
        const dockOpen = !root.classList.contains('translate-x-full');
        tab.setAttribute('aria-expanded', dockOpen ? 'true' : 'false');
        tab.setAttribute('aria-hidden', 'false');
    }

    function setDockExpanded(expanded) {
        if (!root) return;
        root.classList.toggle('translate-x-full', !expanded);
        root.setAttribute('aria-hidden', expanded ? 'false' : 'true');
        refreshExpandHandleVisibility();
    }

    function syncDockShellFromAlerts() {
        const shouldExpand = hasUnreadAlerts() && !userCollapsedDockToday();
        setDockExpanded(shouldExpand);
    }

    dockLastHadUnreadAlerts = hasUnreadAlerts();

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function applyTabVisuals(tab) {
        currentTab = tab;
        tabButtons.forEach((btn) => {
            const active = btn.getAttribute('data-tab') === tab;
            btn.classList.toggle('bg-white', active);
            btn.classList.toggle('dark:bg-gray-900', active);
            btn.classList.toggle('shadow-sm', active);
        });
        tabContents.forEach((content) => {
            content.classList.toggle('hidden', content.getAttribute('data-tab-content') !== tab);
        });
    }

    function updateTabHint() {
        if (!tabHint || !root) return;
        let text = '';
        if (currentTab === 'alerts') text = root.dataset.hintAlerts || '';
        else if (currentTab === 'chats') text = root.dataset.hintChats || '';
        else text = root.dataset.hintActive || '';
        tabHint.textContent = text;
    }

    function setTabFromUserClick(tab) {
        applyTabVisuals(tab);
        updateTabHint();
        renderDockLists();
        updateTabCounts();
    }

    function renderRow(row, tabType) {
        const unread = Number(row.unread || 0);
        const rowKey = escapeHtml(row.conversation_key);
        const profileUrl = escapeHtml(row.profile_url || '#');
        const chatUrl = escapeHtml(row.url || '#');
        const startChatUrl = escapeHtml(row.start_chat_url || '');
        const hasConversation = !!row.has_conversation;
        const labelProfile = escapeHtml(root.dataset.labelProfile || 'Profile');
        const labelOpenChat = escapeHtml(root.dataset.labelOpenChat || 'Open chat');

        const avatar = row.avatar_url
            ? `<img src="${escapeHtml(row.avatar_url)}" alt="${escapeHtml(row.name || 'Member')}" class="h-10 w-10 rounded-full object-cover ring-1 ring-black/10">`
            : `<span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-red-100 text-xs font-bold text-red-700">${escapeHtml((row.name || 'M').trim().charAt(0).toUpperCase())}</span>`;

        const openChatControl = hasConversation
            ? `<a href="${chatUrl}" class="rounded-md border border-red-200 px-2 py-0.5 text-[10px] font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300">${labelOpenChat}</a>`
            : `<form method="POST" action="${startChatUrl}" class="m-0 inline">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}" />
                    <button type="submit" class="rounded-md border border-red-200 px-2 py-0.5 text-[10px] font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300">${labelOpenChat}</button>
               </form>`;

        const rightBadge = unread > 0
            ? `<span class="inline-flex min-w-[1.2rem] items-center justify-center rounded-full bg-green-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">${unread}</span>`
            : (tabType === 'active' ? '<span class="text-[10px] font-semibold text-gray-500">Active</span>' : '<span></span>');

        return `
            <div class="chat-dock-row flex w-full items-start gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2.5 transition hover:border-red-200 hover:bg-red-50/60 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-red-800 dark:hover:bg-red-950/20">
                <button
                    type="button"
                    class="chat-dock-row-main flex min-w-0 flex-1 items-start gap-2 text-left"
                    data-row-key="${rowKey}"
                >
                    <div class="shrink-0">${avatar}</div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">${escapeHtml(row.name || 'Member')}</p>
                            ${rightBadge}
                        </div>
                        <p class="truncate text-xs text-gray-600 dark:text-gray-300">${escapeHtml(row.preview || 'No messages yet')}</p>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">${escapeHtml(row.time || '')}</p>
                    </div>
                </button>
                <div class="flex shrink-0 flex-col items-end gap-1 pt-0.5">
                    <a href="${profileUrl}" class="rounded-md border border-red-200 px-2 py-0.5 text-[10px] font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300">${labelProfile}</a>
                    ${openChatControl}
                </div>
            </div>
        `;
    }

    function attachRowHandlers(container, rowsData) {
        container.querySelectorAll('.chat-dock-row-main').forEach((el) => {
            el.addEventListener('click', () => {
                const rowKey = el.getAttribute('data-row-key');
                const conversation = rowsData.find((r) => String(r.conversation_key) === String(rowKey));
                if (!conversation) return;
                openPopoutFromConversation(conversation);
            });
        });
    }

    function renderDockLists() {
        const alertsHost = root.querySelector('[data-tab-content="alerts"]');
        const chatsHost = root.querySelector('[data-tab-content="chats"]');
        const activeHost = root.querySelector('[data-tab-content="active"]');
        if (!alertsHost || !chatsHost || !activeHost) return;

        const unreadRows = dockData.unread || [];
        const chatsRows = dockData.chats || [];
        const activeRows = dockData.active || [];

        alertsHost.innerHTML = unreadRows.length
            ? unreadRows.map((r) => renderRow(r, 'alerts')).join('')
            : '<p class="rounded-lg border border-dashed border-gray-300 bg-white px-3 py-4 text-center text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">No alerts.</p>';
        chatsHost.innerHTML = chatsRows.length
            ? chatsRows.map((r) => renderRow(r, 'chats')).join('')
            : '<p class="rounded-lg border border-dashed border-gray-300 bg-white px-3 py-4 text-center text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">No chats yet.</p>';
        activeHost.innerHTML = activeRows.length
            ? activeRows.map((r) => renderRow(r, 'active')).join('')
            : '<p class="rounded-lg border border-dashed border-gray-300 bg-white px-3 py-4 text-center text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">No active members.</p>';

        attachRowHandlers(alertsHost, unreadRows);
        attachRowHandlers(chatsHost, chatsRows);
        attachRowHandlers(activeHost, activeRows);
    }

    function updateTabCounts() {
        const unreadCount = Number(dockData.unread_count || 0);
        if (tabAlertsCount) tabAlertsCount.textContent = String((dockData.unread || []).length);
        if (tabActiveCount) tabActiveCount.textContent = String((dockData.active || []).length);
        if (dockBadge) {
            dockBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
            dockBadge.classList.toggle('hidden', unreadCount <= 0);
        }
    }

    function setPopoutMinimized(card, minimized) {
        card.dataset.minimized = minimized ? '1' : '0';
        const body = card.querySelector('[data-popout-body]');
        const mini = card.querySelector('[data-popout-minimize]');
        if (body) body.classList.toggle('hidden', minimized);
        if (mini) mini.textContent = minimized ? '▢' : '−';
        card.classList.toggle('hidden', minimized);
    }

    function renderPopupMessages(card, htmlList, lastId) {
        const thread = card.querySelector('[data-popout-thread]');
        if (!thread) return;
        if (!Array.isArray(htmlList) || htmlList.length === 0) return;
        htmlList.forEach((chunk) => {
            const nodeWrap = document.createElement('div');
            nodeWrap.innerHTML = chunk;
            const node = nodeWrap.firstElementChild;
            if (node) thread.appendChild(node);
        });
        thread.scrollTop = thread.scrollHeight;
        card.dataset.lastId = String(lastId || card.dataset.lastId || '0');
    }

    async function fetchConversationForCard(card, sinceId) {
        if (card.dataset.hasConversation !== '1') return;
        const chatUrl = card.dataset.chatUrl;
        if (!chatUrl) return;
        const url = new URL(chatUrl, window.location.origin);
        url.searchParams.set('since_id', String(sinceId || 0));
        const response = await fetch(url.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (!response.ok) return;
        const data = await response.json();
        const htmlList = Array.isArray(data.html) ? data.html : [];
        if (sinceId === 0) {
            const thread = card.querySelector('[data-popout-thread]');
            if (thread) thread.innerHTML = '';
        }
        renderPopupMessages(card, htmlList, data.last_id || sinceId || 0);
    }

    async function ensureConversationStarted(card) {
        if (card.dataset.hasConversation === '1') {
            return { ok: true };
        }
        const startUrl = card.dataset.startChatUrl;
        if (!startUrl) {
            return { ok: false, message: 'Unable to start this chat from the dock.' };
        }
        const fd = new FormData();
        fd.append('_token', csrf);
        const response = await fetch(startUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: fd,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success !== true) {
            return { ok: false, message: data.message || 'Unable to start conversation.' };
        }
        card.dataset.hasConversation = '1';
        if (data.chat_url) {
            card.dataset.chatUrl = data.chat_url;
        }
        if (data.send_url) {
            card.dataset.sendUrl = data.send_url;
        }
        return { ok: true };
    }

    async function sendInlineFromCard(card, bodyText) {
        const sendUrl = card.dataset.sendUrl;
        if (!sendUrl) return { ok: false, message: 'Send route missing' };
        const response = await fetch(sendUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ body_text: bodyText }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success !== true) {
            return { ok: false, message: data.message || 'Unable to send message.' };
        }
        return { ok: true };
    }

    function ensureChip(payload, card) {
        if (!chipBar) return;
        chipBar.classList.remove('hidden');
        chipBar.classList.add('flex');
        if (minimizedPopouts.has(payload.conversationId)) return;
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'pointer-events-auto inline-flex items-center gap-2 rounded-full border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-md hover:bg-red-50';
        chip.innerHTML = `<span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-100 text-[10px] font-bold text-red-700">${escapeHtml((payload.name || 'M').trim().charAt(0).toUpperCase())}</span><span class="max-w-[8rem] truncate">${escapeHtml(payload.name)}</span>`;
        chip.addEventListener('click', () => {
            setPopoutMinimized(card, false);
            removeChip(payload.conversationId);
        });
        chipBar.appendChild(chip);
        minimizedPopouts.set(payload.conversationId, chip);
    }

    function removeChip(conversationId) {
        const chip = minimizedPopouts.get(conversationId);
        if (chip) {
            chip.remove();
            minimizedPopouts.delete(conversationId);
        }
        if (chipBar && minimizedPopouts.size === 0) {
            chipBar.classList.add('hidden');
            chipBar.classList.remove('flex');
        }
    }

    function stackPopouts() {
        let idx = 0;
        for (const card of openPopouts.values()) {
            if (!card || card.dataset.customPos === '1' || card.dataset.minimized === '1') continue;
            card.style.bottom = '1rem';
            card.style.right = popoutRightOffsetRem(idx) + 'rem';
            card.style.left = 'auto';
            card.style.top = 'auto';
            idx++;
        }
    }

    function createPopoutCard(payload) {
        if (!popoutLayer) return null;
        const safeName = escapeHtml(payload.name);
        const safeTitle = escapeHtml(payload.profileTitle || '');
        const safeSubtitle = escapeHtml(payload.profileSubtitle || '');
        const safeLocation = escapeHtml(payload.profileLocation || '');
        const safeMetaLine = escapeHtml(payload.metaLine || '');
        const safeProfileUrl = escapeHtml(payload.profileUrl || '#');
        const safeChatUrl = escapeHtml(payload.chatUrl || '#');
        const safeAvatarUrl = escapeHtml(payload.avatarUrl || '');
        const avatarHtml = safeAvatarUrl
            ? `<img src="${safeAvatarUrl}" alt="${safeName}" class="h-12 w-12 rounded-full object-cover ring-2 ring-white/70">`
            : `<span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white/20 text-sm font-bold">${escapeHtml((payload.name || 'M').trim().charAt(0).toUpperCase())}</span>`;

        const premiumNote = !dockData.can_read_incoming
            ? `<div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-900">
                    Read access in current plan is limited. You are seeing protected preview samples.
                    <a href="{{ route('plans.index') }}" class="ml-1 font-semibold text-indigo-700 underline">Upgrade</a>
               </div>`
            : '';

        const card = document.createElement('section');
        card.className = 'pointer-events-auto flex h-[34rem] w-[22rem] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900';
        card.dataset.conversationId = payload.conversationId;
        card.dataset.chatUrl = payload.chatUrl;
        card.dataset.sendUrl = payload.sendUrl;
        card.dataset.startChatUrl = payload.startChatUrl;
        card.dataset.hasConversation = payload.hasConversation ? '1' : '0';
        card.dataset.lastId = '0';
        card.dataset.minimized = '0';
        card.dataset.customPos = '0';
        card.style.position = 'fixed';
        card.style.bottom = '1rem';
        card.style.right = popoutRightOffsetRem(openPopouts.size) + 'rem';
        card.style.left = 'auto';
        card.style.top = 'auto';

        card.innerHTML = `
            <header class="cursor-move bg-gradient-to-r from-red-500 to-rose-600 px-3 py-2.5 text-white">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        ${avatarHtml}
                        <div class="min-w-0">
                            <p class="truncate text-base font-bold">${safeName}</p>
                            <p class="truncate text-[11px] text-red-100">${safeTitle}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" data-popout-minimize class="rounded p-1 text-sm font-bold hover:bg-white/20">−</button>
                        <button type="button" data-popout-close class="rounded p-1 text-sm font-bold hover:bg-white/20">×</button>
                    </div>
                </div>
            </header>
            <div data-popout-body class="flex min-h-0 flex-1 flex-col">
                <div class="border-b border-gray-200 bg-white px-3 py-2 text-[12px] dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-center gap-2 text-sky-700">
                        <a href="${safeProfileUrl}" class="font-semibold hover:underline">Full profile</a>
                        <span class="text-gray-300">|</span>
                        <a href="${safeProfileUrl}#report" class="font-semibold hover:underline">Report misuse</a>
                    </div>
                    <p class="mt-1 text-gray-700 dark:text-gray-200">${safeMetaLine}</p>
                    <p class="text-gray-700 dark:text-gray-200">${safeSubtitle}</p>
                    <p class="text-gray-600 dark:text-gray-300">${safeLocation}</p>
                </div>
                <div class="space-y-2 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-950/30">
                    ${premiumNote}
                </div>
                <div data-popout-thread class="min-h-0 flex-1 overflow-y-auto space-y-2 bg-gray-50 px-3 py-3 dark:bg-gray-950"></div>
                <div class="border-t border-gray-200 bg-white px-3 py-2.5 dark:border-gray-700 dark:bg-gray-900">
                    <form data-popout-form class="flex items-end gap-2">
                        <textarea data-popout-input rows="2" maxlength="500" placeholder="Type a message..." class="min-h-[42px] flex-1 resize-none rounded-xl border border-gray-300 px-3 py-2 text-xs text-gray-900 focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-200 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"></textarea>
                        <button type="submit" data-popout-send class="inline-flex h-[42px] items-center justify-center rounded-xl bg-red-600 px-3 text-xs font-semibold text-white hover:bg-red-700">Send</button>
                    </form>
                    <p data-popout-status class="mt-1 hidden text-[10px] font-semibold"></p>
                    <a href="${safeChatUrl}" data-popout-open-full class="mt-1 inline-flex text-[11px] font-semibold text-red-600 hover:underline">Open full chat</a>
                </div>
            </div>
        `;

        const closeBtn = card.querySelector('[data-popout-close]');
        const miniBtn = card.querySelector('[data-popout-minimize]');
        const dragHandle = card.querySelector('header');
        const form = card.querySelector('[data-popout-form]');
        const input = card.querySelector('[data-popout-input]');
        const sendBtn = card.querySelector('[data-popout-send]');
        const statusEl = card.querySelector('[data-popout-status]');

        if (!payload.hasConversation) {
            const thread = card.querySelector('[data-popout-thread]');
            if (thread) {
                thread.innerHTML = '<p class="rounded-xl border border-dashed border-gray-300 bg-white px-3 py-4 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">No messages yet. Type below and press Send — your first message opens the conversation.</p>';
            }
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                openPopouts.delete(payload.conversationId);
                card.remove();
                removeChip(payload.conversationId);
                if (openPopouts.size === 0 && popoutLayer) {
                    popoutLayer.classList.add('hidden');
                    popoutLayer.classList.remove('flex');
                }
            });
        }
        if (miniBtn) {
            miniBtn.addEventListener('click', () => {
                const willMinimize = card.dataset.minimized !== '1';
                setPopoutMinimized(card, willMinimize);
                if (willMinimize) ensureChip(payload, card);
                else removeChip(payload.conversationId);
            });
        }
        if (dragHandle) {
            let dragging = false;
            let startX = 0;
            let startY = 0;
            let rectLeft = 0;
            let rectTop = 0;
            dragHandle.addEventListener('mousedown', (event) => {
                if (event.target.closest('[data-popout-minimize], [data-popout-close]')) return;
                dragging = true;
                startX = event.clientX;
                startY = event.clientY;
                const rect = card.getBoundingClientRect();
                rectLeft = rect.left;
                rectTop = rect.top;
                card.style.left = rectLeft + 'px';
                card.style.top = rectTop + 'px';
                card.style.right = 'auto';
                card.style.bottom = 'auto';
                card.dataset.customPos = '1';
                document.body.classList.add('select-none');
            });
            window.addEventListener('mousemove', (event) => {
                if (!dragging) return;
                const dx = event.clientX - startX;
                const dy = event.clientY - startY;
                card.style.left = Math.max(0, rectLeft + dx) + 'px';
                card.style.top = Math.max(60, rectTop + dy) + 'px';
            });
            window.addEventListener('mouseup', () => {
                if (!dragging) return;
                dragging = false;
                document.body.classList.remove('select-none');
            });
        }
        if (form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const bodyText = ((input && input.value) || '').trim();
                if (!bodyText) return;
                if (sendBtn) sendBtn.disabled = true;
                if (statusEl) statusEl.classList.add('hidden');
                if (card.dataset.hasConversation !== '1') {
                    const started = await ensureConversationStarted(card);
                    if (!started.ok) {
                        if (statusEl) {
                            statusEl.textContent = started.message || 'Unable to start chat.';
                            statusEl.className = 'mt-1 text-[10px] font-semibold text-red-600';
                            statusEl.classList.remove('hidden');
                        }
                        if (sendBtn) sendBtn.disabled = false;
                        return;
                    }
                    const thread = card.querySelector('[data-popout-thread]');
                    if (thread) thread.innerHTML = '';
                    card.dataset.lastId = '0';
                    const fullLink = card.querySelector('[data-popout-open-full]');
                    if (fullLink && card.dataset.chatUrl) {
                        fullLink.setAttribute('href', card.dataset.chatUrl);
                    }
                    await fetchConversationForCard(card, 0);
                }
                const sent = await sendInlineFromCard(card, bodyText);
                if (!sent.ok) {
                    if (statusEl) {
                        statusEl.textContent = sent.message;
                        statusEl.className = 'mt-1 text-[10px] font-semibold text-red-600';
                        statusEl.classList.remove('hidden');
                    }
                } else {
                    if (input) input.value = '';
                    await fetchConversationForCard(card, Number(card.dataset.lastId || 0));
                    if (statusEl) {
                        statusEl.textContent = 'Sent';
                        statusEl.className = 'mt-1 text-[10px] font-semibold text-emerald-600';
                        statusEl.classList.remove('hidden');
                    }
                }
                if (sendBtn) sendBtn.disabled = false;
            });
        }

        return card;
    }

    async function openPopoutFromConversation(conversation) {
        if (!popoutLayer) return;
        const metaBits = [conversation.profile_age, conversation.profile_height, conversation.profile_religion, conversation.profile_caste]
            .map((v) => (v || '').trim())
            .filter(Boolean);
        let metaLine = metaBits.join(', ');
        if (!metaLine) {
            const fallback = [(conversation.profile_title || '').trim(), (conversation.profile_location || '').trim()].filter(Boolean);
            metaLine = fallback.join(' · ');
        }
        const secondaryBits = [conversation.profile_occupation, conversation.profile_education]
            .map((v) => (v || '').trim())
            .filter(Boolean);
        const headerTitle = (conversation.profile_title || '').trim() || metaLine;
        const payload = {
            conversationId: String(conversation.conversation_key || ''),
            name: conversation.name || 'Member',
            avatarUrl: conversation.avatar_url || '',
            profileUrl: conversation.profile_url || '#',
            chatUrl: conversation.url || '#',
            sendUrl: conversation.send_url || '',
            startChatUrl: conversation.start_chat_url || '',
            hasConversation: !!conversation.has_conversation,
            profileTitle: headerTitle,
            profileSubtitle: secondaryBits.length ? secondaryBits.join(', ') : ((conversation.profile_subtitle || '').trim() || ''),
            profileLocation: conversation.profile_location || '',
            metaLine,
        };
        if (!payload.conversationId) return;

        if (openPopouts.has(payload.conversationId)) {
            const existing = openPopouts.get(payload.conversationId);
            if (existing) {
                setPopoutMinimized(existing, false);
                removeChip(payload.conversationId);
                await fetchConversationForCard(existing, Number(existing.dataset.lastId || 0));
            }
            return;
        }

        if (openPopouts.size >= 2) {
            const firstKey = openPopouts.keys().next().value;
            const firstCard = openPopouts.get(firstKey);
            if (firstCard) firstCard.remove();
            openPopouts.delete(firstKey);
            removeChip(firstKey);
        }

        const card = createPopoutCard(payload);
        if (!card) return;
        popoutLayer.classList.remove('hidden');
        popoutLayer.classList.add('flex');
        popoutLayer.appendChild(card);
        openPopouts.set(payload.conversationId, card);
        stackPopouts();
        await fetchConversationForCard(card, 0);
    }

    async function pollOpenPopouts() {
        if (openPopouts.size === 0) return;
        for (const card of openPopouts.values()) {
            if (!card || card.dataset.minimized === '1' || card.dataset.hasConversation !== '1') continue;
            await fetchConversationForCard(card, Number(card.dataset.lastId || 0));
        }
    }

    async function pollChatDockSnapshot() {
        try {
            const response = await fetch(@json(route('member.widgets.chat-dock')), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || data.ok !== true || !data.chat_dock) return;
            const next = data.chat_dock;
            dockData = {
                unread_count: Number(next.unread_count || 0),
                unread: Array.isArray(next.unread) ? next.unread : [],
                chats: Array.isArray(next.chats) ? next.chats : [],
                active: Array.isArray(next.active) ? next.active : [],
                can_read_incoming: !!next.can_read_incoming,
            };

            applyTabVisuals(currentTab);
            updateTabHint();
            renderDockLists();
            updateTabCounts();
            if (typeof window.floatingPanelsSyncChatUnread === 'function') {
                window.floatingPanelsSyncChatUnread(Number(dockData.unread_count || 0));
            }
            const nowUnread = hasUnreadAlerts();
            const allowAutoExpand = !userCollapsedDockToday();
            if (nowUnread && allowAutoExpand) {
                setDockExpanded(true);
            } else if (dockLastHadUnreadAlerts && !nowUnread) {
                setDockExpanded(false);
            }
            dockLastHadUnreadAlerts = nowUnread;
        } catch (_e) {}
    }

    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            setTabFromUserClick(btn.getAttribute('data-tab') || 'alerts');
        });
    });

    applyTabVisuals('alerts');
    updateTabHint();
    renderDockLists();
    updateTabCounts();
    if (typeof window.floatingPanelsSyncChatUnread === 'function') {
        window.floatingPanelsSyncChatUnread(Number(dockData.unread_count || 0));
    }
    syncDockShellFromAlerts();
    refreshExpandHandleVisibility();
    window.addEventListener('resize', refreshExpandHandleVisibility);

    document.addEventListener('floating-panel-opened', (e) => {
        const p = e.detail && e.detail.panel;
        if (p === 'chat') {
            setDockExpanded(true);
            refreshExpandHandleVisibility();
        }
    });

    if (headerMinBtn) {
        headerMinBtn.addEventListener('click', () => {
            rememberCollapsedDockToday();
            setDockExpanded(false);
            if (typeof window.clearFloatingPanelActive === 'function') {
                window.clearFloatingPanelActive();
            }
        });
    }
    document.addEventListener('click', function onGlobalChatTabClick(e) {
        const tab = e.target.closest('#chatTab');
        if (!tab) return;
        const dockOpen = !root.classList.contains('translate-x-full');
        if (dockOpen) {
            setDockExpanded(false);
            if (typeof window.releaseFloatingPanelExclusive === 'function') {
                window.releaseFloatingPanelExclusive();
            }
            return;
        }
        if (typeof window.openPanel === 'function') {
            window.openPanel('chat');
        } else {
            setDockExpanded(true);
        }
    });

    document.addEventListener('member-widget-counts-updated', () => {
        pollChatDockSnapshot();
    });

    setInterval(pollOpenPopouts, 5000);
    setInterval(pollChatDockSnapshot, 15000);
})();
</script>
@endif
@endauth
