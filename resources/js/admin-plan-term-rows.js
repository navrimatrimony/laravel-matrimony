/**
 * Paid plan billing rows: clone template + reindex term_rows[n][*]; radio default_billing_key synced with selects.
 */

function syncDefaultRadioFromSelect(row) {
    const sel = row.querySelector('.js-plan-billing-key-select');
    const radio = row.querySelector('.js-plan-default-radio');
    if (!sel || !radio) {
        return;
    }
    radio.value = sel.value;
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

    function reindex() {
        body.querySelectorAll('[data-plan-term-row]').forEach((row, i) => {
            row.querySelectorAll('[name]').forEach((el) => {
                el.name = el.name.replace(/term_rows\[\d+]/, `term_rows[${i}]`);
            });
            syncDefaultRadioFromSelect(row);
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

    body.querySelectorAll('[data-plan-term-row]').forEach(syncDefaultRadioFromSelect);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminPlanTermRows);
} else {
    initAdminPlanTermRows();
}
