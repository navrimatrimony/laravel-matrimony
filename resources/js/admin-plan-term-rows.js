/**
 * Paid plan billing rows: clone template + reindex; live discount %; integer-only money fields (no spinners).
 */

function syncDefaultRadioFromSelect(row) {
    const sel = row.querySelector('.js-plan-billing-key-select');
    const radio = row.querySelector('.js-plan-default-radio');
    if (!sel || !radio) {
        return;
    }
    radio.value = sel.value;
}

function digitsOnly(raw) {
    return String(raw ?? '').replace(/\D+/g, '');
}

function displayDiscountPercent(mrp, selling) {
    const m = Number(mrp);
    const s = Number(selling);
    if (!Number.isFinite(m) || m <= 0 || !Number.isFinite(s) || s >= m) {
        return 0;
    }
    return Math.round(((m - s) / m) * 100);
}

function refreshDiscountDisplay(row) {
    const mrpEl = row.querySelector('.js-plan-mrp');
    const sellEl = row.querySelector('.js-plan-selling');
    const out = row.querySelector('.js-plan-discount-display');
    if (!mrpEl || !sellEl || !out) {
        return;
    }
    const mrp = Number(digitsOnly(mrpEl.value) || '0');
    const selling = Number(digitsOnly(sellEl.value) || '0');
    const pct = displayDiscountPercent(mrp, selling);
    const emptyLabel = out.getAttribute('data-empty-label') || '—';
    out.textContent = pct > 0 ? `${pct}% OFF` : emptyLabel;
    if (selling > mrp && mrp > 0) {
        sellEl.setCustomValidity('Selling Price must not exceed MRP');
    } else {
        sellEl.setCustomValidity('');
    }
}

function coerceIntegerField(el) {
    if (!(el instanceof HTMLInputElement)) {
        return;
    }
    const cleaned = digitsOnly(el.value);
    if (el.value !== cleaned) {
        el.value = cleaned;
    }
}

export function initAdminPlanTermRows() {
    const body = document.getElementById('plan-term-rows-body');
    const tpl = document.getElementById('admin-plan-term-row-template');
    const btnAdd = document.getElementById('plan-term-row-add');

    if (!body || !tpl || !btnAdd) {
        return;
    }

    body.addEventListener('change', (e) => {
        if (!e.target.classList.contains('js-plan-billing-key-select')) {
            return;
        }
        const row = e.target.closest('[data-plan-term-row]');
        if (row) {
            syncDefaultRadioFromSelect(row);
        }
    });

    body.addEventListener('input', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement)) {
            return;
        }
        if (t.classList.contains('js-plan-mrp')
            || t.classList.contains('js-plan-selling')
            || t.classList.contains('js-plan-int-money')
            || t.classList.contains('js-plan-int-pct')) {
            coerceIntegerField(t);
        }
        if (t.classList.contains('js-plan-mrp') || t.classList.contains('js-plan-selling')) {
            const row = t.closest('[data-plan-term-row]');
            if (row) {
                refreshDiscountDisplay(row);
            }
        }
    });

    function reindex() {
        body.querySelectorAll('[data-plan-term-row]').forEach((row, i) => {
            row.querySelectorAll('[name]').forEach((el) => {
                el.name = el.name.replace(/term_rows\[\d+]/, `term_rows[${i}]`);
            });
            syncDefaultRadioFromSelect(row);
            refreshDiscountDisplay(row);
        });
    }

    btnAdd.addEventListener('click', () => {
        const node = tpl.content.cloneNode(true).firstElementChild;
        if (node) {
            body.appendChild(node);
            reindex();
        }
    });

    body.addEventListener('click', (e) => {
        const rm = e.target.closest('[data-plan-term-row-remove]');
        if (!rm) {
            return;
        }
        const rows = body.querySelectorAll('[data-plan-term-row]');
        if (rows.length <= 1) {
            return;
        }
        rm.closest('[data-plan-term-row]')?.remove();
        reindex();
    });

    body.querySelectorAll('[data-plan-term-row]').forEach((row) => {
        syncDefaultRadioFromSelect(row);
        refreshDiscountDisplay(row);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminPlanTermRows);
} else {
    initAdminPlanTermRows();
}
