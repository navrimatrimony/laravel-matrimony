/**
 * Religion / Caste / Subcaste selector — class-based, multi-instance.
 * Simple absolute dropdowns inside component (no portal).
 */

(function () {
    'use strict';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        const input = document.querySelector('input[name="_token"]');
        return input ? input.value : '';
    }

    function filterOptions(options, q) {
        if (!q) return options;
        const lower = q.toLowerCase();
        return options.filter(function (o) {
            return o.label && o.label.toLowerCase().indexOf(lower) !== -1;
        });
    }

    function exactMatch(options, q) {
        if (!q) return false;
        const lower = q.toLowerCase();
        return options.some(function (o) {
            return o.label && o.label.toLowerCase() === lower;
        });
    }

    function initComponent(root) {
        const religionInput = root.querySelector('.religion-input');
        const religionHidden = root.querySelector('.religion-hidden');
        const religionDropdown = root.querySelector('.religion-dropdown');
        const religionWrap = root.querySelector('.religion-wrap');
        const religionDataEl = root.querySelector('.religion-options-data');
        const religionOptions = religionDataEl && religionDataEl.textContent
            ? JSON.parse(religionDataEl.textContent)
            : [];

        const casteInput = root.querySelector('.caste-input');
        const casteHidden = root.querySelector('.caste-hidden');
        const casteDropdown = root.querySelector('.caste-dropdown');
        const casteWrap = root.querySelector('.caste-wrap');

        const subInput = root.querySelector('.subcaste-input');
        const subHidden = root.querySelector('.subcaste-hidden');
        const subDropdown = root.querySelector('.subcaste-dropdown');
        const subcasteWrap = root.querySelector('.subcaste-wrap');

        let castesCache = [];

        function getCasteId() {
            return (casteHidden && casteHidden.value) || '';
        }

        function onReligionChange() {
            const rid = religionHidden ? religionHidden.value : '';
            castesCache = [];
            if (casteHidden) casteHidden.value = '';
            if (casteInput) {
                casteInput.value = '';
                casteInput.disabled = !rid;
            }
            if (casteDropdown) casteDropdown.classList.add('hidden');
            if (subHidden) subHidden.value = '';
            if (subInput) {
                subInput.value = '';
                subInput.disabled = true;
            }
            if (subDropdown) subDropdown.classList.add('hidden');
            if (!rid) return;
            fetch('/api/castes/' + rid)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    castesCache = data || [];
                });
        }

        // Religion
        if (religionInput && religionHidden && religionDropdown && religionOptions.length) {
            function renderReligionOptions(filtered) {
                religionDropdown.innerHTML = '';
                filtered.forEach(function (r) {
                    const div = document.createElement('div');
                    div.className = 'block w-full text-left px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 last:border-b-0';
                    div.textContent = r.label;
                    div.dataset.id = r.id;
                    div.dataset.label = r.label;
                    div.addEventListener('click', function () {
                        religionHidden.value = this.dataset.id;
                        religionInput.value = this.dataset.label;
                        religionDropdown.classList.add('hidden');
                        onReligionChange();
                    });
                    religionDropdown.appendChild(div);
                });
                if (filtered.length > 0) {
                    religionDropdown.classList.remove('hidden');
                } else {
                    religionDropdown.classList.add('hidden');
                }
            }
            religionInput.addEventListener('input', function () {
                const q = this.value.trim();
                renderReligionOptions(filterOptions(religionOptions, q));
            });
            religionInput.addEventListener('focus', function () {
                const q = this.value.trim();
                renderReligionOptions(filterOptions(religionOptions, q));
            });
            document.addEventListener('click', function (e) {
                if (religionWrap && !religionWrap.contains(e.target) && religionDropdown && !religionDropdown.contains(e.target)) {
                    religionDropdown.classList.add('hidden');
                }
            });
        }

        // Caste
        if (casteInput && casteHidden && casteDropdown) {
            function renderCasteOptions(filtered) {
                casteDropdown.innerHTML = '';
                filtered.forEach(function (c) {
                    const div = document.createElement('div');
                    div.className = 'block w-full text-left px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 last:border-b-0';
                    div.textContent = c.label;
                    div.dataset.id = c.id;
                    div.dataset.label = c.label;
                    div.addEventListener('click', function () {
                        casteHidden.value = this.dataset.id;
                        casteInput.value = this.dataset.label;
                        casteDropdown.classList.add('hidden');
                        if (subInput) subInput.disabled = false;
                    });
                    casteDropdown.appendChild(div);
                });
                if (filtered.length > 0) {
                    casteDropdown.classList.remove('hidden');
                } else {
                    casteDropdown.classList.add('hidden');
                }
            }
            casteInput.addEventListener('input', function () {
                const q = this.value.trim();
                renderCasteOptions(filterOptions(castesCache, q));
            });
            casteInput.addEventListener('focus', function () {
                const q = this.value.trim();
                renderCasteOptions(filterOptions(castesCache, q));
            });
            document.addEventListener('click', function (e) {
                if (casteWrap && !casteWrap.contains(e.target) && casteDropdown && !casteDropdown.contains(e.target)) {
                    casteDropdown.classList.add('hidden');
                }
            });
        }

        // Subcaste: min 2 chars, add-new when no exact match
        let subDebounce = null;
        function showSubCasteDropdown(casteId, q) {
            if (!subDropdown || !subInput) return;
            if (q.length < 2) {
                subDropdown.innerHTML = '';
                subDropdown.classList.add('hidden');
                return;
            }
            const url = '/api/subcastes/' + casteId + '?q=' + encodeURIComponent(q);
            fetch(url)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    const results = Array.isArray(data) ? data : [];
                    const typed = subInput.value.trim();
                    const hasExact = exactMatch(results, typed);
                    const showAddNew = typed.length >= 2 && !hasExact;

                    subDropdown.innerHTML = '';
                    results.forEach(function (item) {
                        const div = document.createElement('div');
                        div.className = 'px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
                        div.textContent = item.label;
                        div.dataset.id = item.id;
                        div.dataset.label = item.label;
                        div.addEventListener('click', function () {
                            subHidden.value = this.dataset.id;
                            subInput.value = this.dataset.label;
                            subDropdown.classList.add('hidden');
                        });
                        subDropdown.appendChild(div);
                    });
                    if (showAddNew) {
                        const addNew = document.createElement('div');
                        addNew.className = 'px-3 py-2 cursor-pointer border-t border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 text-indigo-600 dark:text-indigo-400';
                        addNew.textContent = 'Add new subcaste: "' + typed + '"';
                        addNew.addEventListener('click', function () {
                            const label = subInput.value.trim();
                            if (label.length < 2) return;
                            addNew.textContent = 'Adding…';
                            fetch('/api/sub-castes', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': getCsrfToken(),
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({ caste_id: casteId, label: label })
                            })
                                .then(function (r) { return r.json(); })
                                .then(function (row) {
                                    subHidden.value = row.id;
                                    subInput.value = row.label;
                                    subDropdown.classList.add('hidden');
                                })
                                .catch(function () {
                                    addNew.textContent = 'Add new subcaste: "' + typed + '"';
                                });
                        });
                        subDropdown.appendChild(addNew);
                    }

                    if (results.length > 0 || showAddNew) {
                        subDropdown.classList.remove('hidden');
                    } else {
                        subDropdown.classList.add('hidden');
                    }
                });
        }

        if (subInput && subDropdown && subHidden) {
            subInput.addEventListener('input', function () {
                const casteId = getCasteId();
                if (!casteId) return;
                clearTimeout(subDebounce);
                const q = this.value.trim();
                if (q.length < 2) {
                    subDropdown.innerHTML = '';
                    subDropdown.classList.add('hidden');
                    return;
                }
                subDebounce = setTimeout(function () {
                    showSubCasteDropdown(casteId, q);
                }, 250);
            });
            subInput.addEventListener('focus', function () {
                const casteId = getCasteId();
                if (!casteId) return;
                const q = this.value.trim();
                if (q.length >= 2) showSubCasteDropdown(casteId, q);
            });
            document.addEventListener('click', function (e) {
                if (subcasteWrap && !subcasteWrap.contains(e.target) && subDropdown && !subDropdown.contains(e.target)) {
                    subDropdown.classList.add('hidden');
                }
            });
        }

        // Initial values: religion label
        if (religionOptions.length && religionHidden && religionHidden.value) {
            const r = religionOptions.find(function (o) {
                return String(o.id) === String(religionHidden.value);
            });
            if (r && religionInput) religionInput.value = r.label;
        }
        const ridForCastes = religionHidden ? religionHidden.value : '';
        if (ridForCastes) {
            fetch('/api/castes/' + ridForCastes)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    castesCache = data || [];
                    if (casteHidden && casteHidden.value && casteInput) {
                        const c = castesCache.find(function (o) {
                            return String(o.id) === String(casteHidden.value);
                        });
                        if (c) {
                            casteInput.value = c.label;
                            casteInput.disabled = false;
                        }
                    }
                });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const components = document.querySelectorAll('.religion-caste-component');
        components.forEach(initComponent);
    });
})();
