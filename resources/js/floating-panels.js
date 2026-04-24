/**
 * Mutual exclusivity + badges for Help Centre flyout vs Communication dock (UI only).
 * Panels stay in the DOM; visibility uses Tailwind `hidden` / inline display for chat wrapper.
 */

let activePanel = null;
let chatUnread = 0;
let helpUnread = 0;

function getHelpEl() {
    return document.getElementById('helpPanel');
}

function getChatEl() {
    return document.getElementById('chatPanel');
}

export function updateBadge(type, count) {
    const el = document.getElementById(`${type}Badge`);
    if (!el) return;
    const n = Math.max(0, Number(count) || 0);
    if (n > 0) {
        el.textContent = n > 99 ? '99+' : String(n);
        el.classList.remove('hidden');
    } else {
        el.classList.add('hidden');
    }
}

function setChatPanelSuppressed(suppress) {
    const chatEl = getChatEl();
    if (!chatEl) return;
    if (suppress) {
        chatEl.style.setProperty('display', 'none', 'important');
    } else {
        chatEl.style.removeProperty('display');
    }
}

export function openPanel(panel) {
    const helpEl = getHelpEl();
    const chatEl = getChatEl();
    if (!helpEl || !chatEl) return;
    // Do not short-circuit when re-clicking chat: dock may still be collapsed while activePanel is 'chat'.

    helpEl.classList.add('hidden');
    setChatPanelSuppressed(true);

    if (panel === 'help') {
        helpEl.classList.remove('hidden');
        helpUnread = 0;
        updateBadge('help', 0);
        activePanel = 'help';
        document.dispatchEvent(new CustomEvent('floating-panel-opened', { detail: { panel: 'help' } }));
    } else if (panel === 'chat') {
        setChatPanelSuppressed(false);
        chatEl.classList.remove('hidden');
        chatUnread = 0;
        updateBadge('chat', 0);
        activePanel = 'chat';
        document.dispatchEvent(new CustomEvent('floating-panel-opened', { detail: { panel: 'chat' } }));
    }
}

export function releaseFloatingPanelExclusive() {
    activePanel = null;
    const helpEl = getHelpEl();
    const chatEl = getChatEl();
    if (helpEl) helpEl.classList.add('hidden');
    setChatPanelSuppressed(false);
    if (chatEl) chatEl.classList.remove('hidden');
}

export function clearFloatingPanelActive() {
    activePanel = null;
}

export function bumpChatUnread() {
    if (activePanel !== 'chat') {
        chatUnread += 1;
        updateBadge('chat', chatUnread);
    }
}

export function bumpHelpUnread() {
    if (activePanel !== 'help') {
        helpUnread += 1;
        updateBadge('help', helpUnread);
    }
}

export function syncFloatingChatUnreadFromServer(total) {
    const n = Math.max(0, Number(total) || 0);
    if (activePanel !== 'chat') {
        chatUnread = n;
        updateBadge('chat', n);
    } else {
        chatUnread = 0;
        updateBadge('chat', 0);
    }
}

if (typeof window !== 'undefined') {
    window.openPanel = openPanel;
    window.releaseFloatingPanelExclusive = releaseFloatingPanelExclusive;
    window.clearFloatingPanelActive = clearFloatingPanelActive;
    window.floatingPanelsUpdateBadge = updateBadge;
    window.floatingPanelsBumpChat = bumpChatUnread;
    window.floatingPanelsBumpHelp = bumpHelpUnread;
    window.floatingPanelsSyncChatUnread = syncFloatingChatUnreadFromServer;
}
