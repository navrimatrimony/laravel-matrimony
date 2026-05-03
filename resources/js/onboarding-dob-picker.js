/**
 * Registration onboarding DOB: IMask DD/MM/YYYY (smart segments + delete), numeric keypad,
 * hidden ISO submit; optional Flatpickr via calendar button (anchor year on open).
 * Guard: .cursor/rules/ONBOARDING-DOB-PICKER.mdc — do not revert to plain flatpickr-only without updating tests + rule.
 */
import IMask from 'imask';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';

function pad2(n) {
    return String(n).padStart(2, '0');
}

/** @param {string} iso */
function isoToDisplay(iso) {
    if (!iso || typeof iso !== 'string') {
        return '';
    }
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) {
        return '';
    }
    return `${m[3]}/${m[2]}/${m[1]}`;
}

/** @param {string} str */
function parseDisplayToDate(str) {
    const m = str.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) {
        return null;
    }
    const d = parseInt(m[1], 10);
    const mo = parseInt(m[2], 10);
    const y = parseInt(m[3], 10);
    const dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) {
        return null;
    }
    return dt;
}

/** @param {Date} dt */
function formatIso(dt) {
    return `${dt.getFullYear()}-${pad2(dt.getMonth() + 1)}-${pad2(dt.getDate())}`;
}

/**
 * @param {ParentNode} [root]
 */
export function initOnboardingDobPickers(root = document) {
    root.querySelectorAll('[data-onboarding-dob-wrap]').forEach((wrap) => {
        if (wrap.dataset.dobMaskInit === '1') {
            return;
        }
        wrap.dataset.dobMaskInit = '1';

        const hidden = wrap.querySelector('input[name="date_of_birth"]');
        const display = wrap.querySelector('[data-onboarding-dob-display]');
        const btn = wrap.querySelector('[data-onboarding-dob-calendar]');
        const form = wrap.closest('form');
        if (!hidden || !display || !btn) {
            return;
        }

        const anchorYear = parseInt(String(wrap.getAttribute('data-dob-anchor-year') || ''), 10);
        const minStr = wrap.getAttribute('data-dob-min') || '';
        const maxStr = wrap.getAttribute('data-dob-max') || '';
        const minYear = parseInt(minStr.slice(0, 4), 10) || 1900;
        const maxYear = parseInt(maxStr.slice(0, 4), 10) || 2100;
        const minDate = minStr ? new Date(`${minStr}T00:00:00`) : null;
        const maxDate = maxStr ? new Date(`${maxStr}T23:59:59`) : null;

        if (hidden.value && !display.value) {
            display.value = isoToDisplay(hidden.value);
        }

        const hasSavedIso = Boolean(String(hidden.value || '').trim());

        const mask = IMask(display, {
            mask: 'd{/}m{/}Y',
            lazy: !hasSavedIso,
            blocks: {
                d: { mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2, autofix: 'pad' },
                m: { mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2, autofix: 'pad' },
                Y: { mask: IMask.MaskedRange, from: minYear, to: maxYear, maxLength: 4 },
            },
        });

        function expandMaskForTyping() {
            mask.updateOptions({ lazy: false });
        }

        function collapseMaskIfNoDigits() {
            const digits = String(display.value || '').replace(/\D/g, '');
            if (digits.length > 0) {
                return;
            }
            mask.updateOptions({ lazy: true });
            display.value = '';
            mask.updateValue();
            syncHiddenFromMask();
        }

        display.addEventListener('focus', expandMaskForTyping);
        display.addEventListener('input', expandMaskForTyping);
        display.addEventListener('blur', collapseMaskIfNoDigits);

        /** @param {Date} dt */
        function inRange(dt) {
            if (minDate && dt < minDate) {
                return false;
            }
            if (maxDate && dt > maxDate) {
                return false;
            }
            return true;
        }

        function syncHiddenFromMask() {
            const dt = parseDisplayToDate(display.value.trim());
            if (dt && inRange(dt)) {
                hidden.value = formatIso(dt);
            } else {
                hidden.value = '';
            }
        }

        mask.on('accept', syncHiddenFromMask);

        const fpHook = document.createElement('input');
        fpHook.type = 'text';
        fpHook.tabIndex = -1;
        fpHook.setAttribute('aria-hidden', 'true');
        fpHook.className = 'pointer-events-none fixed left-0 top-0 h-px w-px overflow-hidden opacity-0';
        wrap.appendChild(fpHook);

        const fp = flatpickr(fpHook, {
            dateFormat: 'Y-m-d',
            minDate: minStr || undefined,
            maxDate: maxStr || undefined,
            clickOpens: false,
            disableMobile: true,
            appendTo: document.body,
            positionElement: btn,
            defaultDate: hidden.value || undefined,
            onOpen: (selectedDates, dateStr, instance) => {
                if (!dateStr && !hidden.value) {
                    const y = Number.isFinite(anchorYear) ? anchorYear : new Date().getFullYear() - 25;
                    instance.jumpToDate(new Date(y, 5, 15), false);
                }
            },
            onChange: (selectedDates, _dateStr, instance) => {
                if (!selectedDates[0]) {
                    return;
                }
                const d = selectedDates[0];
                hidden.value = instance.formatDate(d, 'Y-m-d');
                mask.updateOptions({ lazy: false });
                mask.value = `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()}`;
            },
        });

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (hidden.value) {
                fp.setDate(hidden.value, false, 'Y-m-d');
            } else {
                fp.clear();
            }
            fp.open();
        });

        if (form) {
            form.addEventListener('submit', () => {
                const dt = parseDisplayToDate(display.value.trim());
                if (dt && inRange(dt)) {
                    hidden.value = formatIso(dt);
                } else if (!display.value.replace(/\D/g, '').length) {
                    hidden.value = '';
                } else {
                    hidden.value = '';
                }
            });
        }
    });
}
