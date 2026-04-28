import { initSearchableSingleSelect } from './init-searchable-select';

/**
 * Rebuild a cloned row's Tom Select wrapper into a clean select + inert mount, then init.
 * @param {HTMLElement} root
 */
function resetClonedOccupationRoot(root) {
    if (!root) {
        return;
    }
    const sel = root.querySelector('select[data-searchable-single]');
    if (sel && sel.tomselect) {
        try {
            sel.tomselect.destroy();
        } catch (_e) {
            /* ignore */
        }
    }
    const master = root.querySelector('input[name*="master_id"]');
    const custom = root.querySelector('input[name*="custom_id"]');
    const masterName = master ? master.name : '';
    const customName = custom ? custom.name : '';
    while (root.firstChild) {
        root.removeChild(root.firstChild);
    }
    const formFieldLayout = root.classList && root.classList.contains('occupation-engine--form-field');
    const h1 = document.createElement('input');
    h1.type = 'hidden';
    h1.name = masterName;
    h1.id = 'occ-master-' + Math.random().toString(36).slice(2, 11);
    h1.value = '';
    const h2 = document.createElement('input');
    h2.type = 'hidden';
    h2.name = customName;
    h2.id = 'occ-custom-' + Math.random().toString(36).slice(2, 11);
    h2.value = '';
    const selNew = document.createElement('select');
    selNew.id = 'occupation-ts-' + Math.random().toString(36).slice(2, 12);
    selNew.setAttribute('data-searchable-single', '');
    selNew.autocomplete = 'off';
    const formField = formFieldLayout;
    selNew.className = formField
        ? 'w-full occupation-ts-source'
        : 'w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm min-h-[40px]';
    const cat = document.createElement('div');
    cat.id = 'occupation-category-' + Math.random().toString(36).slice(2, 11);
    cat.setAttribute('data-occupation-category-mount', '');
    cat.className = formField
        ? 'absolute left-0 top-full z-10 mt-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400 p-0 m-0 border-0 bg-transparent pointer-events-none'
        : 'mt-2 text-sm text-gray-600 dark:text-gray-400 min-h-[2rem]';
    root.appendChild(h1);
    root.appendChild(h2);
    if (formField) {
        const wrap = document.createElement('div');
        wrap.className = 'relative w-full min-w-0 overflow-visible';
        wrap.appendChild(selNew);
        wrap.appendChild(cat);
        root.appendChild(wrap);
    } else {
        root.appendChild(selNew);
        root.appendChild(cat);
    }

    let cfg = {};
    try {
        cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    } catch (_e) {
        cfg = {};
    }
    cfg.selectSelector = '#' + selNew.id;
    cfg.hiddenMasterSelector = '#' + h1.id;
    cfg.hiddenCustomSelector = '#' + h2.id;
    cfg.categoryMountSelector = '#' + cat.id;
    cfg.initialSelection = null;
    if (formField) {
        cfg.compactCategoryMount = true;
    }
    root.setAttribute('data-config', JSON.stringify(cfg));
}

function mountOccupationEngine(root) {
    if (!root || root.getAttribute('data-occ-inited') === '1') {
        return;
    }
    let cfg = {};
    try {
        cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    } catch (_e) {
        cfg = {};
    }
    initSearchableSingleSelect(Object.assign({ root }, cfg));
    root.setAttribute('data-occ-inited', '1');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-init-occupation-engine]').forEach((root) => {
        mountOccupationEngine(root);
    });
});

document.addEventListener('repeater:row-added', (e) => {
    const row = e.detail && e.detail.row;
    if (!row || !row.querySelector) {
        return;
    }
    row.querySelectorAll('[data-init-occupation-engine]').forEach((root) => {
        resetClonedOccupationRoot(root);
        root.removeAttribute('data-occ-inited');
        mountOccupationEngine(root);
    });
});
