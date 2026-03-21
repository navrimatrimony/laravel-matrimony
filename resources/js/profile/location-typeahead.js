/**
 * Reusable location/address typeahead — single component, used in wizard (residence/work/native)
 * and alliance rows. Uses /api/internal/location/search.
 */
(function () {
    'use strict';

    var SEARCH_URL = '/api/internal/location/search';
    var APP_LOCALE = (document.documentElement && document.documentElement.lang) ? document.documentElement.lang.split('-')[0] : 'en';
    var DEBOUNCE_MS = 200;

    function buildLine(item) {
        var cityName = item.city_name || item.label || item.name || '';
        return cityName + ', ' + (item.taluka_name || '') + ', ' + (item.district_name || '') + ', ' + (item.state_name || '');
    }

    function openSuggestModal(wrapper, name) {
        var tpl = document.getElementById('location-suggest-modal-template');
        if (!tpl) return;
        var frag = document.createElement('div');
        frag.innerHTML = tpl.innerHTML.trim();
        var backdrop = frag.children[0];
        var modal = frag.children[1];
        var inner = modal.querySelector('.location-suggest-modal-inner');
        var nameDisplay = modal.querySelector('.location-suggest-name-display');
        var stateSelect = modal.querySelector('.location-suggest-state');
        var districtSelect = modal.querySelector('.location-suggest-district');
        var talukaSelect = modal.querySelector('.location-suggest-taluka');
        var errorEl = modal.querySelector('.location-suggest-error');
        var successEl = modal.querySelector('.location-suggest-success');

        nameDisplay.textContent = name;

        function close() {
            backdrop.remove();
            modal.remove();
        }

        modal.querySelector('.location-suggest-close').addEventListener('click', close);
        modal.querySelector('.location-suggest-cancel').addEventListener('click', close);
        backdrop.addEventListener('click', close);

        function setError(msg) {
            if (!errorEl) return;
            errorEl.textContent = msg;
            errorEl.classList.remove('hidden');
            if (successEl) successEl.classList.add('hidden');
        }

        function setSuccess(msg) {
            if (!successEl) return;
            successEl.textContent = msg;
            successEl.classList.remove('hidden');
            if (errorEl) errorEl.classList.add('hidden');
        }

        function loadStates(selectedId) {
            fetch('/api/internal/location/states', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !Array.isArray(data.data)) return;
                    stateSelect.innerHTML = '<option value=\"\">Select state</option>';
                    data.data.forEach(function (row) {
                        var opt = document.createElement('option');
                        opt.value = row.id;
                        opt.textContent = row.name;
                        stateSelect.appendChild(opt);
                    });
                    if (selectedId) {
                        stateSelect.value = String(selectedId);
                        stateSelect.dispatchEvent(new Event('change'));
                    }
                })
                .catch(function () {});
        }

        function loadDistricts(stateId, selectedId) {
            districtSelect.innerHTML = '<option value=\"\">Select district</option>';
            talukaSelect.innerHTML = '<option value=\"\">Select taluka</option>';
            if (!stateId) return;
            fetch('/api/internal/location/districts?state_id=' + encodeURIComponent(stateId), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !Array.isArray(data.data)) return;
                    data.data.forEach(function (row) {
                        var opt = document.createElement('option');
                        opt.value = row.id;
                        opt.textContent = row.name;
                        districtSelect.appendChild(opt);
                    });
                    if (selectedId) {
                        districtSelect.value = String(selectedId);
                        districtSelect.dispatchEvent(new Event('change'));
                    }
                })
                .catch(function () {});
        }

        function loadTalukas(districtId, selectedId) {
            talukaSelect.innerHTML = '<option value=\"\">Select taluka</option>';
            if (!districtId) return;
            fetch('/api/internal/location/talukas?district_id=' + encodeURIComponent(districtId), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !Array.isArray(data.data)) return;
                    data.data.forEach(function (row) {
                        var opt = document.createElement('option');
                        opt.value = row.id;
                        opt.textContent = row.name;
                        talukaSelect.appendChild(opt);
                    });
                    if (selectedId) {
                        talukaSelect.value = String(selectedId);
                    }
                })
                .catch(function () {});
        }

        stateSelect.addEventListener('change', function () {
            loadDistricts(stateSelect.value, null);
        });
        districtSelect.addEventListener('change', function () {
            loadTalukas(districtSelect.value, null);
        });

        // Try to preselect from wrapper hidden fields / profile context if available
        var stateHidden = (wrapper.querySelector('.location-hidden-state') || {}).value || '';
        var districtHidden = (wrapper.querySelector('.location-hidden-district') || {}).value || '';
        var talukaHidden = (wrapper.querySelector('.location-hidden-taluka') || {}).value || '';
        loadStates(stateHidden || null);
        if (stateHidden) {
            loadDistricts(stateHidden, districtHidden || null);
            if (districtHidden) {
                loadTalukas(districtHidden, talukaHidden || null);
            }
        }

        modal.querySelector('.location-suggest-submit').addEventListener('click', function () {
            var stateId = stateSelect.value;
            var districtId = districtSelect.value;
            var talukaId = talukaSelect.value;
            if (!stateId || !districtId || !talukaId) {
                setError('Please select state, district and taluka.');
                return;
            }

            var context = wrapper.dataset.locationContext || 'residence';
            var countryId = (wrapper.querySelector('.location-hidden-country') || {}).value || 1;
            var payload = {
                suggested_name: name,
                country_id: countryId || 1,
                state_id: stateId,
                district_id: districtId,
                taluka_id: talukaId,
                suggestion_type: 'village',
            };

            fetch('/api/internal/location/suggest', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]') ? document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content') : ''
                },
                body: JSON.stringify(payload),
            }).then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        setSuccess('Thanks! Location submitted for admin approval.');
                        setTimeout(close, 900);
                    } else {
                        var msg = (data && data.message) ? data.message : 'Could not submit suggestion.';
                        setError(msg);
                    }
                })
                .catch(function () {
                    setError('Network error while submitting suggestion.');
                });
        });

        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        inner.focus();
    }

    function renderResults(wrapper, resultsEl, data, onSelect) {
        if (!data.success || !data.data || data.data.length === 0) {
            var q = (wrapper.querySelector('.location-typeahead-input').value || '').trim();
            var html = '<div class="p-2 text-gray-500">No matches</div>';
            if (q.length >= 3) {
                html += '' +
                    '<button type="button" class="w-full text-left px-3 py-2 text-sm bg-rose-50 hover:bg-rose-100 text-rose-700 border-t border-rose-200 location-suggest-btn" data-suggest-name="' + q.replace(/"/g, '&quot;') + '">' +
                    '➕ Add "' + q.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '" as village / city' +
                    '</button>' +
                    '<div class="px-3 py-1 text-xs text-gray-500">We’ll send this to admin for approval.</div>';
            }
            resultsEl.innerHTML = html;
            resultsEl.classList.remove('hidden');
            resultsEl.style.display = 'block';

            var suggestBtn = resultsEl.querySelector('.location-suggest-btn');
            if (suggestBtn) {
                suggestBtn.addEventListener('click', function () {
                    openSuggestModal(wrapper, suggestBtn.getAttribute('data-suggest-name') || q);
                });
            }
            return;
        }
        resultsEl.innerHTML = '';
        data.data.forEach(function (item) {
            var cityId = item.city_id || item.id || '';
            var div = document.createElement('div');
            div.className = 'p-2 border-b border-gray-200 dark:border-gray-600 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
            div.textContent = buildLine(item);
            div.addEventListener('click', function () {
                onSelect(item, buildLine(item));
            });
            resultsEl.appendChild(div);
        });
        resultsEl.classList.remove('hidden');
        resultsEl.style.display = 'block';
    }

    function bindWrapper(wrapper) {
        if (wrapper.dataset.bound === '1') return;
        var context = wrapper.dataset.locationContext || 'residence';
        var input = wrapper.querySelector('.location-typeahead-input');
        var resultsEl = wrapper.querySelector('.location-typeahead-results');
        if (!input || !resultsEl) return;
        wrapper.dataset.bound = '1';

        function onSelect(item, displayLabel) {
            input.value = displayLabel;
            resultsEl.classList.add('hidden');
            resultsEl.style.display = 'none';

            var cityId = item.city_id || item.id || '';
            var talukaId = item.taluka_id || '';
            var districtId = item.district_id || '';
            var stateId = item.state_id || '';
            var countryId = item.country_id || '';

            if (context === 'residence') {
                var country = wrapper.querySelector('.location-hidden-country');
                var state = wrapper.querySelector('.location-hidden-state');
                var district = wrapper.querySelector('.location-hidden-district');
                var taluka = wrapper.querySelector('.location-hidden-taluka');
                var city = wrapper.querySelector('.location-hidden-city');
                if (country) country.value = countryId;
                if (state) state.value = stateId;
                if (district) district.value = districtId;
                if (taluka) taluka.value = talukaId;
                if (city) city.value = cityId;
                // Backup for submit: some stacks clear hiddens; dataset survives on the wrapper.
                wrapper.dataset.resCountryId = countryId || '';
                wrapper.dataset.resStateId = stateId || '';
                wrapper.dataset.resDistrictId = districtId || '';
                wrapper.dataset.resTalukaId = talukaId || '';
                wrapper.dataset.resCityId = cityId || '';
            } else if (context === 'work') {
                var workCity = wrapper.querySelector('.location-hidden-work-city');
                var workState = wrapper.querySelector('.location-hidden-work-state');
                if (workCity) workCity.value = cityId;
                if (workState) workState.value = stateId;
            } else if (context === 'native') {
                var nCity = wrapper.querySelector('.location-hidden-native-city');
                var nTaluka = wrapper.querySelector('.location-hidden-native-taluka');
                var nDistrict = wrapper.querySelector('.location-hidden-native-district');
                var nState = wrapper.querySelector('.location-hidden-native-state');
                if (nCity) nCity.value = cityId;
                if (nTaluka) nTaluka.value = talukaId;
                if (nDistrict) nDistrict.value = districtId;
                if (nState) nState.value = stateId;
            } else if (context === 'birth') {
                var bCity = wrapper.querySelector('.location-hidden-birth-city');
                var bTaluka = wrapper.querySelector('.location-hidden-birth-taluka');
                var bDistrict = wrapper.querySelector('.location-hidden-birth-district');
                var bState = wrapper.querySelector('.location-hidden-birth-state');
                if (bCity) bCity.value = cityId;
                if (bTaluka) bTaluka.value = talukaId;
                if (bDistrict) bDistrict.value = districtId;
                if (bState) bState.value = stateId;
            } else if (context === 'alliance') {
                var c = wrapper.querySelector('.location-hidden-city');
                var t = wrapper.querySelector('.location-hidden-taluka');
                var d = wrapper.querySelector('.location-hidden-district');
                var s = wrapper.querySelector('.location-hidden-state');
                if (c) c.value = cityId;
                if (t) t.value = talukaId;
                if (d) d.value = districtId;
                if (s) s.value = stateId;
                var row = wrapper.closest('.property-asset-row');
                if (row) {
                    var sync = row.querySelector('.location-display-sync');
                    if (sync) sync.value = displayLabel;
                }
            }
            var displaySyncName = wrapper.getAttribute('data-display-sync-name');
            if (displaySyncName) {
                var form = wrapper.closest('form');
                if (form) {
                    var found = null;
                    form.querySelectorAll('input[name]').forEach(function(inp) {
                        if (inp.getAttribute('name') === displaySyncName) found = inp;
                    });
                    if (found) found.value = displayLabel;
                }
            }
        }

        var debounce = null;
        input.addEventListener('input', function () {
            clearTimeout(debounce);
            var q = input.value.trim();
            if (q.length < 2) {
                resultsEl.classList.add('hidden');
                resultsEl.style.display = 'none';
                return;
            }
            debounce = setTimeout(function () {
                fetch(SEARCH_URL + '?q=' + encodeURIComponent(q) + '&locale=' + encodeURIComponent(APP_LOCALE), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        renderResults(wrapper, resultsEl, data, onSelect);
                    });
            }, DEBOUNCE_MS);
        });
        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 2 && resultsEl.innerHTML) {
                resultsEl.classList.remove('hidden');
                resultsEl.style.display = 'block';
            }
        });
        document.addEventListener('click', function (e) {
            if (wrapper.contains(e.target)) return;
            resultsEl.classList.add('hidden');
            resultsEl.style.display = 'none';
        });

        if (context === 'residence') {
            var hc = wrapper.querySelector('.location-hidden-country');
            var hs = wrapper.querySelector('.location-hidden-state');
            var hd = wrapper.querySelector('.location-hidden-district');
            var ht = wrapper.querySelector('.location-hidden-taluka');
            var hcity = wrapper.querySelector('.location-hidden-city');
            if (hc && hc.value) wrapper.dataset.resCountryId = hc.value;
            if (hs && hs.value) wrapper.dataset.resStateId = hs.value;
            if (hd && hd.value) wrapper.dataset.resDistrictId = hd.value;
            if (ht && ht.value) wrapper.dataset.resTalukaId = ht.value;
            if (hcity && hcity.value) wrapper.dataset.resCityId = hcity.value;
        }
    }

    /** Copy visible typeahead text into the named sync field (e.g. career_history[n][location]) so free-typed text posts. */
    function syncLocationDisplaySyncTargets(form) {
        if (!form || !form.elements) return;
        form.querySelectorAll('.location-typeahead-wrapper[data-display-sync-name]').forEach(function (wrapper) {
            var syncName = wrapper.getAttribute('data-display-sync-name');
            if (!syncName) return;
            var vis = wrapper.querySelector('.location-typeahead-input');
            if (!vis) return;
            var found = null;
            for (var i = 0; i < form.elements.length; i++) {
                var el = form.elements[i];
                if (el.name === syncName) {
                    found = el;
                    break;
                }
            }
            if (!found) return;
            found.value = vis.value != null ? vis.value : '';
        });
    }

    /** Before native submit: restore residence IDs from dataset if hiddens were cleared. */
    function restoreResidenceHiddensFromDataset(form) {
        if (!form || !form.querySelectorAll) return;
        form.querySelectorAll('.location-typeahead-wrapper[data-location-context="residence"]').forEach(function (wrapper) {
            var country = wrapper.querySelector('.location-hidden-country');
            var state = wrapper.querySelector('.location-hidden-state');
            var district = wrapper.querySelector('.location-hidden-district');
            var taluka = wrapper.querySelector('.location-hidden-taluka');
            var city = wrapper.querySelector('.location-hidden-city');
            if (country && !String(country.value || '').trim() && wrapper.dataset.resCountryId) {
                country.value = wrapper.dataset.resCountryId;
            }
            if (state && !String(state.value || '').trim() && wrapper.dataset.resStateId) {
                state.value = wrapper.dataset.resStateId;
            }
            if (district && !String(district.value || '').trim() && wrapper.dataset.resDistrictId) {
                district.value = wrapper.dataset.resDistrictId;
            }
            if (taluka && !String(taluka.value || '').trim() && wrapper.dataset.resTalukaId) {
                taluka.value = wrapper.dataset.resTalukaId;
            }
            if (city && !String(city.value || '').trim() && wrapper.dataset.resCityId) {
                city.value = wrapper.dataset.resCityId;
            }
        });
    }

    function init() {
        document.querySelectorAll('.location-typeahead-wrapper').forEach(bindWrapper);
    }

    document.addEventListener('submit', function (e) {
        var t = e.target;
        if (!t || t.tagName !== 'FORM') return;
        restoreResidenceHiddensFromDataset(t);
        syncLocationDisplaySyncTargets(t);
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.LocationTypeahead = { init: init };
})();
