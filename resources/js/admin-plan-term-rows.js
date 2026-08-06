/**
 * Paid plan billing rows: clone template + reindex term_rows[n][*]; radio default_billing_key synced with selects.
 * Live discount % preview from MRP + Selling Price (display only).
 */

function syncDefaultRadioFromSelect(row) {
    const sel = row.querySelector('.js-plan-billing-key-select');
    const radio = row.querySelector('.js-plan-default-radio');
    if (!sel || !radio) {
        return;
    }
    radio.value = sel.value;
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
    const pct = displayDiscountPercent(mrpEl.value, sellEl.value);
    const emptyLabel = out.getAttribute('data-empty-label') || '—';
    if (pct > 0) {
        out.textContent = `${pct}% OFF`;
        sellEl.setCustomValidity('');
        if (Number(sellEl.value) > Number(mrpEl.value)) {
            sellEl.setCustomValidity('Selling Price must not exceed MRP');
        }
    } else {
        out.textContent = emptyLabel;
        if (Number.isFinite(Number(sellEl.value)) && Number.isFinite(Number(mrpEl.value)) && Number(sellEl.value) > Number(mrpEl.value)) {
            sellEl.setCustomValidity('Selling Price must not exceed MRP');
        } else {
            sellEl.setCustomValidity('');
        }
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
        if (!e.target.classList.contains('js-plan-mrp') && !e.target.classList.contains('js-plan-selling')) {
            return;
        }
        const row = e.target.closest('[data-plan-term-row]');
        if (row) {
            refreshDiscountDisplay(row);
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
