/**
 * Centralized Laravel validation UX: humanized messages in section slots + scroll to the problem area.
 * No field red borders (see product preference).
 */

/** Space below fixed nav when scrolling errors into view */
const SCROLL_NAV_OFFSET_PX = 96;

let scrollDone = false;

/**
 * Laravel uses 0-based indices in keys (children.0.*). Show 1-based "Child 1", "Child 2" to users.
 */
export function humanizeValidationMessage(raw) {
    let s = String(raw ?? '');
    s = s.replace(/(?:snapshot\.)?children\.(\d+)\./gi, (_, idx) => {
        const n = parseInt(idx, 10) + 1;
        return `Child ${n} `;
    });
    s = s.replace(/\b(?:snapshot\.)?children\.(\d+)\b/gi, (_, idx) => {
        const n = parseInt(idx, 10) + 1;
        return `Child ${n}`;
    });
    return s;
}

function dotKeyToFormName(key) {
    const parts = String(key).split('.');
    if (parts.length === 0) {
        return '';
    }
    let name = parts[0];
    for (let i = 1; i < parts.length; i += 1) {
        name += `[${parts[i]}]`;
    }
    return name;
}

function childIndexFromKey(key) {
    const m = String(key).match(/^(?:snapshot\.)?children\.(\d+)\./);
    return m ? m[1] : null;
}

function isMaritalDetailsSlotKey(key) {
    const k = String(key);
    if (/^(?:snapshot\.)?marriages\.\d+\./.test(k)) {
        return true;
    }
    return k === 'has_children' || k === 'snapshot.core.has_children';
}

function clearPreviousUi() {
    document.querySelectorAll('[data-lv-inline-error]').forEach((n) => n.remove());
    document.querySelectorAll('[data-lv-errors-slot]').forEach((slot) => {
        slot.innerHTML = '';
    });
}

function findControlForKey(key) {
    const name = dotKeyToFormName(key);
    if (!name) {
        return null;
    }
    try {
        const escaped = typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(name) : name.replace(/"/g, '\\"');
        const el = document.querySelector(`[name="${escaped}"]`);
        return el instanceof HTMLElement ? el : null;
    } catch {
        return document.querySelector(`[name="${name}"]`);
    }
}

/**
 * @param {HTMLElement} el
 * @returns {boolean} true if scrolled (element had layout)
 */
function scrollToValidationTarget(el) {
    if (!(el instanceof HTMLElement)) {
        return false;
    }
    const rect = el.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) {
        return false;
    }
    const top = rect.top + window.scrollY - SCROLL_NAV_OFFSET_PX;
    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    try {
        if (el.hasAttribute('tabindex')) {
            el.focus({ preventScroll: true });
        }
    } catch {
        /* ignore */
    }
    return true;
}

/** Prefer specific child row keys over bare `children`, then other fields */
function sortKeysForScroll(keys, messages) {
    const withMessage = keys.filter((k) => messages[k] && messages[k].length && messages[k][0]);
    const priority = (key) => {
        if (childIndexFromKey(key) !== null) {
            return 0;
        }
        if (key === 'children' || key === 'snapshot.children') {
            return 1;
        }
        return 2;
    };
    return withMessage.slice().sort((a, b) => {
        const d = priority(a) - priority(b);
        if (d !== 0) {
            return d;
        }
        return keys.indexOf(a) - keys.indexOf(b);
    });
}

function appendToSlot(slot, message) {
    if (!slot || !message) {
        return;
    }
    let ul = slot.querySelector(':scope > ul[data-lv-inline-error]');
    if (!ul) {
        ul = document.createElement('ul');
        ul.setAttribute('data-lv-inline-error', '1');
        ul.className = 'list-disc list-inside space-y-0.5 text-sm text-red-600 dark:text-red-400';
        slot.appendChild(ul);
    }
    const li = document.createElement('li');
    li.textContent = message;
    ul.appendChild(li);
}

function wrapHasBladeError(wrap) {
    return !!(wrap && wrap.querySelector('p.text-sm.text-red-600, p.text-sm.text-red-400'));
}

function appendMessageBelowWrap(wrap, message) {
    if (!wrap || !message || wrapHasBladeError(wrap)) {
        return;
    }
    const p = document.createElement('p');
    p.setAttribute('data-lv-inline-error', '1');
    p.className = 'mt-1.5 text-sm text-red-600 dark:text-red-400';
    p.textContent = message;
    wrap.appendChild(p);
}

function legacyFieldWrap(control) {
    if (!control) {
        return null;
    }
    return control.closest('[data-lv-highlight-wrap], [data-onboarding-highlight-wrap]');
}

function getScrollTargetForKey(key) {
    if (key === 'children' || key === 'snapshot.children') {
        const section = document.getElementById('marital-children-details');
        if (section instanceof HTMLElement) {
            return section;
        }
    }

    const childIdx = childIndexFromKey(key);
    if (childIdx !== null) {
        const row = document.querySelector(`[data-lv-child-row][data-child-index="${childIdx}"]`);
        if (row instanceof HTMLElement) {
            return row;
        }
        const section = document.getElementById('marital-children-details');
        if (section instanceof HTMLElement) {
            return section;
        }
    }

    if (isMaritalDetailsSlotKey(key)) {
        const block = document.querySelector('[data-lv-scroll-target="marital-details"]');
        if (block instanceof HTMLElement) {
            return block;
        }
        const section = document.querySelector('[data-lv-section="marital-details"]');
        if (section instanceof HTMLElement) {
            return section;
        }
    }

    const control = findControlForKey(key);
    if (control) {
        const explicit = control.closest('[data-lv-scroll-target]');
        if (explicit instanceof HTMLElement) {
            return explicit;
        }
        const wrap = legacyFieldWrap(control);
        if (wrap instanceof HTMLElement) {
            return wrap;
        }
        return control;
    }

    return null;
}

/**
 * @param {{ keys: string[], messages: Record<string, string[]> }} payload
 */
export function applyLaravelValidationUi(payload) {
    if (!payload || !Array.isArray(payload.keys) || !payload.messages) {
        return;
    }

    clearPreviousUi();

    const maritalSlot = document.querySelector('[data-lv-errors-slot="marital-details"]');
    const childrenSectionSlot = document.querySelector('[data-lv-errors-slot="children-section"]');

    for (const key of payload.keys) {
        const msgs = payload.messages[key];
        const raw = Array.isArray(msgs) && msgs.length ? msgs[0] : '';
        const message = humanizeValidationMessage(raw);
        if (!message) {
            continue;
        }

        if ((key === 'children' || key === 'snapshot.children') && childrenSectionSlot) {
            appendToSlot(childrenSectionSlot, message);
            continue;
        }

        const childIdx = childIndexFromKey(key);
        if (childIdx !== null) {
            const slot = document.querySelector(`[data-lv-child-errors="${childIdx}"]`);
            if (slot) {
                appendToSlot(slot, message);
            }
            continue;
        }

        if (isMaritalDetailsSlotKey(key) && maritalSlot) {
            appendToSlot(maritalSlot, message);
            continue;
        }

        const control = findControlForKey(key);
        const wrap = legacyFieldWrap(control);
        if (wrap) {
            appendMessageBelowWrap(wrap, message);
        }
    }

    if (!scrollDone) {
        const ordered = sortKeysForScroll(payload.keys, payload.messages);
        for (const key of ordered) {
            const target = getScrollTargetForKey(key);
            if (target instanceof HTMLElement && scrollToValidationTarget(target)) {
                scrollDone = true;
                break;
            }
        }
    }

    if (!scrollDone && payload.keys.some((k) => k === 'children' || k === 'snapshot.children' || childIndexFromKey(k) !== null)) {
        const fallback = document.getElementById('marital-children-details');
        if (fallback instanceof HTMLElement && scrollToValidationTarget(fallback)) {
            scrollDone = true;
        }
    }
}

function readPayload() {
    const el =
        document.getElementById('laravel-validation-errors') ||
        document.getElementById('onboarding-validation-errors');
    if (!el || el.tagName !== 'SCRIPT' || el.getAttribute('type') !== 'application/json') {
        return null;
    }
    try {
        return JSON.parse(el.textContent || '{}');
    } catch {
        return null;
    }
}

export function humanizeValidationSummariesInDom() {
    document.querySelectorAll('[data-lv-humanize-summary]').forEach((box) => {
        box.querySelectorAll('p').forEach((p) => {
            const t = p.textContent || '';
            if (/children\.\d+/i.test(t) || /snapshot\.children\.\d+/i.test(t)) {
                p.textContent = humanizeValidationMessage(t);
            }
        });
        if (!box.querySelector('p')) {
            const t = (box.textContent || '').trim();
            if (/children\.\d+/i.test(t) || /snapshot\.children\.\d+/i.test(t)) {
                box.textContent = humanizeValidationMessage(t);
            }
        }
    });
}

export function initLaravelValidationUi() {
    const payload = readPayload();
    if (!payload || !Array.isArray(payload.keys)) {
        return;
    }

    scrollDone = false;

    humanizeValidationSummariesInDom();

    const run = () => {
        humanizeValidationSummariesInDom();
        applyLaravelValidationUi(payload);
    };

    run();
    setTimeout(run, 120);
    setTimeout(run, 350);
    setTimeout(run, 700);
    setTimeout(run, 1100);
    setTimeout(run, 1600);
}
