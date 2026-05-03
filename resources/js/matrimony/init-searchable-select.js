/**
 * Single-select Tom Select with remote search + POST create (Tom Select create callback).
 *
 * @param {object} opts
 * @param {HTMLElement} opts.root
 * @param {string} [opts.selectSelector]
 * @param {string} [opts.hiddenMasterSelector]
 * @param {string} [opts.hiddenCustomSelector]
 * @param {string} [opts.categoryMountSelector]
 * @param {string} opts.searchUrl
 * @param {string} [opts.createUrl]
 * @param {string} [opts.categoryBaseUrl] GET …/category/{id}
 * @param {number} [opts.minQueryLength]
 * @param {string} [opts.csrfToken]
 * @param {string} [opts.formSelector]
 * @param {{type:'master'|'custom', id:number, label:string}|null} [opts.initialSelection]
 * @param {object} [opts.labels]
 * @param {boolean} [opts.compactCategoryMount] Inline wizard fields: omit Workplace caption, flat strip
 */
export function initSearchableSingleSelect(opts) {
    const root = opts.root;
    var TS = typeof TomSelect !== 'undefined' ? TomSelect : typeof window !== 'undefined' ? window.TomSelect : undefined;
    if (!root || !TS) {
        return null;
    }

    const selectEl = root.querySelector(opts.selectSelector || 'select[data-searchable-single]');
    const hiddenMaster = root.querySelector(opts.hiddenMasterSelector || 'input[name="occupation_master_id"]');
    const hiddenCustom = root.querySelector(opts.hiddenCustomSelector || 'input[name="occupation_custom_id"]');
    const categoryEl = root.querySelector(opts.categoryMountSelector || '[data-occupation-category-mount]');

    const searchUrl = opts.searchUrl;
    const createUrl = opts.createUrl || '';
    const categoryBaseUrl = opts.categoryBaseUrl || '';
    const minQueryLength = opts.minQueryLength ?? 2;
    const L = opts.labels || {};
    /** Wizard inline fields: drop “Workplace” caption + tight strip (matches plain inputs). */
    const compactCm = opts.compactCategoryMount === true;

    let searchAbort = null;
    /** True after last completed search returned ≥1 master row — suppress custom "Add" when matches exist (same rule everywhere). */
    let lastSearchReturnedMatches = false;

    /** Single-line search: no literal newlines; Enter must not submit the wizard when the suggestion list is closed. */
    function bindOccupationControlGuards(rootEl) {
        function tryBind() {
            const inp = rootEl.querySelector('.ts-control input');
            if (!inp || inp.dataset.occGuard === '1') {
                return;
            }
            inp.dataset.occGuard = '1';
            inp.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.isComposing) {
                    return;
                }
                const wrap = inp.closest('.ts-wrapper');
                if (wrap && wrap.classList.contains('dropdown-active')) {
                    return;
                }
                e.preventDefault();
            });
            inp.addEventListener('input', function () {
                if (/[\r\n]/.test(inp.value)) {
                    inp.value = inp.value.replace(/[\r\n]+/g, ' ');
                }
            });
        }
        queueMicrotask(tryBind);
        setTimeout(tryBind, 50);
    }

    function getCsrfToken() {
        if (opts.csrfToken) {
            return opts.csrfToken;
        }
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function occLangMr() {
        const lang = (document.documentElement.lang || '').toLowerCase();
        return lang === 'mr' || lang.indexOf('mr-') === 0;
    }

    function occCategoryLabel(c) {
        if (c && occLangMr() && c.name_mr) {
            return String(c.name_mr);
        }
        return String((c && c.name) || '');
    }

    function occRowLabel(row) {
        if (row && occLangMr() && row.name_mr) {
            return String(row.name_mr);
        }
        return String((row && row.name) || '');
    }

    function syncHiddens(val) {
        if (!hiddenMaster || !hiddenCustom) {
            return;
        }
        hiddenMaster.value = '';
        hiddenCustom.value = '';
        if (!val) {
            return;
        }
        const s = String(val);
        if (s.startsWith('m:')) {
            hiddenMaster.value = s.slice(2);
        } else if (s.startsWith('c:')) {
            hiddenCustom.value = s.slice(2);
        }
    }

    function renderCategoryMount(payload, isCustom) {
        if (!categoryEl) {
            return;
        }
        /** Inline wizard fields: hide workplace rail until selection + API data so the shell stays one row tall. */
        if (!isCustom && !payload && compactCm) {
            categoryEl.innerHTML = '';
            return;
        }
        if (isCustom) {
            const customIco = L.customWorkplaceIcon || '✨';
            if (compactCm) {
                categoryEl.innerHTML =
                    '<span class="occupation-category-ico select-none shrink-0" aria-hidden="true">' +
                    escHtml(customIco) +
                    '</span>' +
                    '<span class="truncate" style="max-width:10rem">' +
                    escHtml(L.customPending || 'Custom occupation (pending review)') +
                    '</span>';
                return;
            }
            categoryEl.innerHTML =
                '<div class="space-y-1">' +
                '<span class="text-xs font-medium text-gray-500 dark:text-gray-400">' +
                escHtml(L.categoryPrefix || 'Workplace') +
                '</span>' +
                '<div class="occupation-workplace-strip">' +
                '<span class="occupation-category-ico text-xl leading-none select-none" aria-hidden="true">' +
                escHtml(customIco) +
                '</span>' +
                '<span class="text-sm font-medium text-gray-700 dark:text-gray-200">' +
                escHtml(L.customPending || 'Custom occupation (pending review)') +
                '</span>' +
                '</div></div>';
            return;
        }
        const cat = payload && payload.category;
        const cats = (payload && payload.categories) || [];
        const currentName = cat ? occCategoryLabel(cat) : '—';
        const currentIcon = cat && cat.icon ? cat.icon : '📋';

        if (compactCm) {
            let htmlCm =
                '<span class="occupation-category-ico select-none shrink-0" aria-hidden="true">' +
                escHtml(currentIcon) +
                '</span>' +
                '<span class="occupation-category-current">' +
                escHtml(currentName) +
                '</span>' +
                '<select class="occupation-category-inline rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 align-middle hidden min-w-[6rem]" aria-label="' +
                escHtml(L.categoryAria || 'Change workplace type') +
                '">';
            cats.forEach((c) => {
                const ic = c.icon || '📋';
                const nm = occCategoryLabel(c);
                const selAttr = cat && String(cat.id) === String(c.id) ? ' selected' : '';
                htmlCm +=
                    '<option value="' +
                    escHtml(String(c.id)) +
                    '" data-icon="' +
                    escHtml(ic) +
                    '" data-label="' +
                    escHtml(nm) +
                    '"' +
                    selAttr +
                    '>' +
                    escHtml(ic + '\u00A0' + nm) +
                    '</option>';
            });
            htmlCm +=
                '</select>' +
                '<button type="button" class="occupation-category-toggle">' +
                escHtml(L.change || 'change') +
                '</button>';
            categoryEl.innerHTML = htmlCm;
            const sel = categoryEl.querySelector('.occupation-category-inline');
            const cur = categoryEl.querySelector('.occupation-category-current');
            const btn = categoryEl.querySelector('.occupation-category-toggle');
            const icoEl = categoryEl.querySelector('.occupation-category-ico');
            if (btn && sel && cur && icoEl) {
                btn.addEventListener('click', () => {
                    sel.classList.toggle('hidden');
                    btn.classList.toggle('hidden');
                });
                sel.addEventListener('change', () => {
                    const opt = sel.options[sel.selectedIndex];
                    if (opt) {
                        const ic = opt.getAttribute('data-icon') || '📋';
                        const lbl = opt.getAttribute('data-label') || '';
                        icoEl.textContent = ic;
                        cur.textContent = lbl;
                    }
                    sel.classList.add('hidden');
                    btn.classList.remove('hidden');
                });
            }
            return;
        }

        let html =
            '<div class="space-y-1">' +
            '<span class="text-xs font-medium text-gray-500 dark:text-gray-400">' +
            escHtml(L.categoryPrefix || 'Workplace') +
            '</span>' +
            '<div class="occupation-workplace-strip">' +
            '<span class="occupation-category-ico text-xl leading-none select-none" aria-hidden="true">' +
            escHtml(currentIcon) +
            '</span>' +
            '<span class="occupation-category-current text-sm font-semibold text-gray-800 dark:text-gray-100">' +
            escHtml(currentName) +
            '</span>' +
            '<select class="occupation-category-inline text-xs rounded-md border border-emerald-300/80 dark:border-emerald-800 bg-white dark:bg-gray-900 px-2 py-1 align-middle hidden min-w-[10rem]" aria-label="' +
            escHtml(L.categoryAria || 'Change workplace type') +
            '">';
        cats.forEach((c) => {
            const ic = c.icon || '📋';
            const nm = occCategoryLabel(c);
            const selAttr = cat && String(cat.id) === String(c.id) ? ' selected' : '';
            html +=
                '<option value="' +
                escHtml(String(c.id)) +
                '" data-icon="' +
                escHtml(ic) +
                '" data-label="' +
                escHtml(nm) +
                '"' +
                selAttr +
                '>' +
                escHtml(ic + '\u00A0' + nm) +
                '</option>';
        });
        html +=
            '</select>' +
            '<button type="button" class="occupation-category-toggle shrink-0 text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">' +
            escHtml(L.change || 'change') +
            '</button>' +
            '</div></div>';

        categoryEl.innerHTML = html;

        const sel = categoryEl.querySelector('.occupation-category-inline');
        const cur = categoryEl.querySelector('.occupation-category-current');
        const btn = categoryEl.querySelector('.occupation-category-toggle');
        const icoEl = categoryEl.querySelector('.occupation-category-ico');
        if (btn && sel && cur && icoEl) {
            btn.addEventListener('click', () => {
                sel.classList.toggle('hidden');
                btn.classList.toggle('hidden');
            });
            sel.addEventListener('change', () => {
                const opt = sel.options[sel.selectedIndex];
                if (opt) {
                    const ic = opt.getAttribute('data-icon') || '📋';
                    const lbl = opt.getAttribute('data-label') || '';
                    icoEl.textContent = ic;
                    cur.textContent = lbl;
                }
                sel.classList.add('hidden');
                btn.classList.remove('hidden');
            });
        }
    }

    function loadCategory(masterId) {
        if (!categoryBaseUrl || !masterId) {
            renderCategoryMount(null, false);
            return;
        }
        const url = categoryBaseUrl.replace(/\/+$/, '') + '/' + encodeURIComponent(masterId);
        fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((json) => renderCategoryMount(json, false))
            .catch(() => renderCategoryMount(null, false));
    }

    const initial = opts.initialSelection || null;

    const renderTemplates = {
        option: function (data) {
            return (
                '<div class="px-2 py-1.5 text-sm text-gray-800 dark:text-gray-100">' +
                escHtml(data.text) +
                '</div>'
            );
        },
    };
    if (createUrl) {
        renderTemplates.option_create = function (data, escape) {
            const q = String(data.input || '');
            const safeQ = typeof escape === 'function' ? escape(q) : escHtml(q);
            const hint = L.createHint
                ? '<p class="text-gray-500 dark:text-gray-400 text-xs leading-snug mb-2">' +
                  escHtml(L.createHint) +
                  '</p>'
                : '';
            const cta = escHtml(L.createCta != null ? L.createCta : 'Add');
            return (
                '<div class="option create occupation-option-create px-3 py-3 text-sm border-t border-gray-100 dark:border-gray-700 bg-gradient-to-b from-emerald-50/90 to-white dark:from-emerald-950/50 dark:to-gray-900">' +
                hint +
                '<div class="flex flex-wrap items-center justify-between gap-2 sm:gap-3">' +
                '<span class="text-left text-sm text-emerald-800 dark:text-emerald-200 font-semibold leading-tight">' +
                '<span class="text-emerald-600 dark:text-emerald-400 font-medium mr-0.5">+</span> ' +
                'Add &quot;' +
                safeQ +
                '&quot;' +
                '</span>' +
                '<span class="shrink-0 inline-flex items-center px-3 py-1.5 rounded-lg border-2 border-emerald-600 bg-emerald-50 dark:bg-emerald-900/50 text-emerald-900 dark:text-emerald-100 text-xs font-bold shadow-sm ring-1 ring-emerald-500/20">' +
                cta +
                '</span>' +
                '</div></div>'
            );
        };
    }

    let tsRef = null;
    const ts = new TS(selectEl, {
        persist: false,
        maxItems: 1,
        addPrecedence: Boolean(createUrl),
        valueField: 'value',
        labelField: 'text',
        searchField: ['text'],
        options: [],
        preload: false,
        placeholder: L.inputPlaceholder || '',
        create: createUrl
            ? function (input, callback) {
                  const name = String(input || '').trim();
                  if (name.length < minQueryLength) {
                      callback();
                      return;
                  }
                  const token = getCsrfToken();
                  fetch(createUrl, {
                      method: 'POST',
                      headers: {
                          'Content-Type': 'application/json',
                          Accept: 'application/json',
                          'X-Requested-With': 'XMLHttpRequest',
                          'X-CSRF-TOKEN': token,
                      },
                      credentials: 'same-origin',
                      body: JSON.stringify({ name: name }),
                  })
                      .then(function (res) {
                          if (!res.ok) {
                              throw new Error('create failed');
                          }
                          return res.json();
                      })
                      .then(function (data) {
                          if (data == null || data.id == null || data.name == null) {
                              callback();
                              return;
                          }
                          callback({
                              value: 'c:' + data.id,
                              text: data.name,
                          });
                      })
                      .catch(function () {
                          callback();
                      });
              }
            : false,
        createFilter: function (input) {
            const t = String(input || '').trim();
            if (t.length < minQueryLength || !createUrl) {
                return false;
            }
            if (lastSearchReturnedMatches) {
                return false;
            }
            return true;
        },
        shouldLoad: function (query) {
            return String(query || '').trim().length >= minQueryLength;
        },
        score: function () {
            return function () {
                return 1;
            };
        },
        render: renderTemplates,
        load: function (query, callback) {
            const q = String(query || '').trim();
            if (q.length < minQueryLength) {
                callback();
                return;
            }
            if (tsRef && typeof tsRef.clearOptions === 'function') {
                tsRef.clearOptions();
            }
            if (searchAbort) {
                try {
                    searchAbort.abort();
                } catch (_e) {}
            }
            searchAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const url =
                searchUrl + (searchUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
            fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: searchAbort ? searchAbort.signal : undefined,
            })
                .then((r) => r.json())
                .then((json) => {
                    const rows = Array.isArray(json) ? json : json && json.results ? json.results : [];
                    lastSearchReturnedMatches = rows.length > 0;
                    const mapped = rows.map((row) => ({
                        value: 'm:' + row.id,
                        text: occRowLabel(row),
                    }));
                    callback(mapped);
                })
                .catch((err) => {
                    if (err && err.name === 'AbortError') {
                        callback();
                        return;
                    }
                    lastSearchReturnedMatches = false;
                    callback();
                });
        },
        onItemAdd: function () {
            if (typeof this.close === 'function') {
                this.close();
            }
        },
        onChange: function (val) {
            syncHiddens(val);
            if (!val) {
                renderCategoryMount(null, false);
                return;
            }
            const s = String(val);
            if (s.startsWith('m:')) {
                loadCategory(s.slice(2));
            } else {
                renderCategoryMount(null, true);
            }
        },
    });
    tsRef = ts;
    bindOccupationControlGuards(root);

    if (initial && initial.type === 'master' && initial.id) {
        const value = 'm:' + initial.id;
        ts.addOption({ value: value, text: initial.label || '' });
        ts.addItem(value, true);
        loadCategory(initial.id);
    } else if (initial && initial.type === 'custom' && initial.id) {
        const value = 'c:' + initial.id;
        ts.addOption({ value: value, text: initial.label || '' });
        ts.addItem(value, true);
        renderCategoryMount(null, true);
    }

    const form = opts.formSelector ? document.querySelector(opts.formSelector) : root.closest('form');
    if (form) {
        form.addEventListener('submit', () => syncHiddens(ts.getValue()));
    }

    return ts;
}
