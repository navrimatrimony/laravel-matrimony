/**
 * Reusable location/address typeahead — single component, used in wizard (residence/work/native)
 * and alliance rows. Search API reads canonical geo rows ({@code addresses}: name, slug, name_mr; aliases optional).
 * URLs come from Blade {@code data-search-url} so subdirectory installs (e.g. /project/public) resolve correctly.
 * GPS assist (auth): POST web route data-resolve-url — suggestion only; form save → MutationService.
 */
(function () {
    'use strict';

    var SEARCH_URL = '/api/location/search';
    var APP_LOCALE = (document.documentElement && document.documentElement.lang) ? document.documentElement.lang.split('-')[0] : 'en';
    var DEBOUNCE_MS = 500;
    var MIN_SEARCH_CHARS = 3;

    function csrfToken() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function labelFromItem(item) {
        return (item && item.display_label) ? String(item.display_label) : '';
    }

    /** Full URL with q + locale; prefers {@code data-search-url} from wrapper (Laravel url()). */
    function locationSearchRequestUrl(wrapper, q, locale) {
        var base = (wrapper && wrapper.dataset && wrapper.dataset.searchUrl) ? wrapper.dataset.searchUrl : SEARCH_URL;
        try {
            var u = (base.indexOf('http') === 0) ? new URL(base) : new URL(base, window.location.href);
            u.searchParams.set('q', q);
            u.searchParams.set('locale', locale);
            return u.toString();
        } catch (e) {
            return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q) + '&locale=' + encodeURIComponent(locale);
        }
    }

    function clearCanonicalSelection(wrapper) {
        wrapper.querySelectorAll('.location-hidden-location-id').forEach(function (el) { el.value = ''; });
        wrapper.querySelectorAll('.location-hidden-location-input').forEach(function (el) { el.value = ''; });
        wrapper.querySelectorAll('.location-hidden-city').forEach(function (el) { el.value = ''; });
        wrapper.querySelectorAll('.location-hidden-work-city').forEach(function (el) { el.value = ''; });
        wrapper.querySelectorAll('.location-hidden-native-city').forEach(function (el) { el.value = ''; });
        wrapper.querySelectorAll('.location-hidden-birth-city').forEach(function (el) { el.value = ''; });
    }

    /** Match {@see LocationFormatterService} joinDistinct: comma-separated, de-dupe case-insensitive. */
    function joinDistinctParts(parts) {
        var clean = [];
        for (var i = 0; i < parts.length; i++) {
            var value = String(parts[i] != null ? parts[i] : '').trim();
            if (value === '') {
                continue;
            }
            var last = clean[clean.length - 1];
            if (last !== undefined && String(last).toLowerCase() === value.toLowerCase()) {
                continue;
            }
            clean.push(value);
        }
        return clean.join(', ');
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * After “Add place” API success: one line for {@code location_input} (pending admin) + hierarchy hiddens;
     * optional PIN suffix; residence summary strip (amber/sky) for review state.
     */
    function applyPendingPlaceFromModal(wrapper, detail) {
        var placeName = String(detail.placeName || '').trim();
        var stateName = String(detail.stateName || '').trim();
        var districtName = String(detail.districtName || '').trim();
        var talukaName = String(detail.talukaName || '').trim();
        var pinDigits = String(detail.pinDigits || '').replace(/\D+/g, '');
        var stateId = String(detail.stateId || '').trim();
        var districtId = String(detail.districtId || '').trim();
        var talukaId = String(detail.talukaId || '').trim();
        var suggestionId = detail.suggestionId != null ? detail.suggestionId : null;
        var context = wrapper.dataset.locationContext || 'residence';
        var countryId = String(wrapper.dataset.defaultCountryId || '').trim();

        var displayBody = joinDistinctParts([placeName, talukaName, districtName, stateName]);
        var storageLine = displayBody;
        if (pinDigits !== '') {
            storageLine = storageLine === '' ? pinDigits : (storageLine + ' ' + pinDigits);
        }
        if (storageLine.length > 255) {
            storageLine = storageLine.slice(0, 255);
        }

        var vis = wrapper.querySelector('.location-typeahead-input');
        if (vis) {
            vis.value = storageLine;
        }
        wrapper.querySelectorAll('.location-hidden-location-input').forEach(function (el) {
            el.value = storageLine;
        });
        wrapper.querySelectorAll('.location-hidden-location-id').forEach(function (el) {
            el.value = '';
        });

        if (context === 'residence') {
            var country = wrapper.querySelector('.location-hidden-country');
            var state = wrapper.querySelector('.location-hidden-state');
            var district = wrapper.querySelector('.location-hidden-district');
            var taluka = wrapper.querySelector('.location-hidden-taluka');
            if (country && countryId) {
                country.value = countryId;
            }
            if (state) {
                state.value = stateId;
            }
            if (district) {
                district.value = districtId;
            }
            if (taluka) {
                taluka.value = talukaId;
            }
            wrapper.dataset.resCountryId = country && country.value ? country.value : '';
            wrapper.dataset.resStateId = state && state.value ? state.value : '';
            wrapper.dataset.resDistrictId = district && district.value ? district.value : '';
            wrapper.dataset.resTalukaId = taluka && taluka.value ? taluka.value : '';
            wrapper.dataset.resLocationId = '';
        } else if (context === 'work') {
            var wCity = wrapper.querySelector('.location-hidden-work-city');
            var wState = wrapper.querySelector('.location-hidden-work-state');
            if (wCity) {
                wCity.value = '';
            }
            if (wState) {
                wState.value = stateId;
            }
        } else if (context === 'native') {
            var nCity = wrapper.querySelector('.location-hidden-native-city');
            var nTaluka = wrapper.querySelector('.location-hidden-native-taluka');
            var nDistrict = wrapper.querySelector('.location-hidden-native-district');
            var nState = wrapper.querySelector('.location-hidden-native-state');
            if (nCity) {
                nCity.value = '';
            }
            if (nTaluka) {
                nTaluka.value = talukaId;
            }
            if (nDistrict) {
                nDistrict.value = districtId;
            }
            if (nState) {
                nState.value = stateId;
            }
        } else if (context === 'birth') {
            var bCity = wrapper.querySelector('.location-hidden-birth-city');
            var bTaluka = wrapper.querySelector('.location-hidden-birth-taluka');
            var bDistrict = wrapper.querySelector('.location-hidden-birth-district');
            var bState = wrapper.querySelector('.location-hidden-birth-state');
            if (bCity) {
                bCity.value = '';
            }
            if (bTaluka) {
                bTaluka.value = talukaId;
            }
            if (bDistrict) {
                bDistrict.value = districtId;
            }
            if (bState) {
                bState.value = stateId;
            }
        } else if (context === 'alliance') {
            var at = wrapper.querySelector('.location-hidden-taluka');
            var ad = wrapper.querySelector('.location-hidden-district');
            var ast = wrapper.querySelector('.location-hidden-state');
            if (at) {
                at.value = talukaId;
            }
            if (ad) {
                ad.value = districtId;
            }
            if (ast) {
                ast.value = stateId;
            }
            var row = wrapper.closest('.property-asset-row');
            if (row) {
                var sync = row.querySelector('.location-display-sync');
                if (sync) {
                    sync.value = storageLine;
                }
            }
        }

        var summary = wrapper.querySelector('[data-location-pending-summary]');
        if (summary) {
            summary.classList.remove('hidden');
            var pinHtml = '';
            if (pinDigits !== '') {
                pinHtml =
                    '<span class="text-emerald-600 dark:text-emerald-400 font-semibold tabular-nums">PIN ' +
                    escapeHtml(pinDigits) +
                    '</span>';
            } else {
                pinHtml =
                    '<span class="text-sky-600 dark:text-sky-400 font-medium">' +
                    'Pin optional — not set' +
                    '</span>';
            }
            var refHtml =
                suggestionId != null
                    ? '<span class="text-gray-500 dark:text-gray-400 ml-1 tabular-nums">#' +
                      escapeHtml(String(suggestionId)) +
                      '</span>'
                    : '';
            summary.innerHTML =
                '<div class="flex flex-wrap items-center gap-x-2 gap-y-1">' +
                '<span class="inline-flex items-center rounded-full bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-200 px-2 py-0.5 text-[11px] font-medium">' +
                'Pending admin review' +
                '</span>' +
                pinHtml +
                refHtml +
                '</div>' +
                '<div class="text-[11px] mt-0.5 leading-snug">' +
                escapeHtml(displayBody) +
                '</div>';
        }
    }

    function clearPendingSummary(wrapper) {
        var summary = wrapper.querySelector('[data-location-pending-summary]');
        if (summary) {
            summary.classList.add('hidden');
            summary.innerHTML = '';
        }
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
        var placeTypeSelect = modal.querySelector('.location-suggest-place-type');
        var pincodeInput = modal.querySelector('.location-suggest-pincode');
        var errorEl = modal.querySelector('.location-suggest-error');
        var successEl = modal.querySelector('.location-suggest-success');

        nameDisplay.textContent = name;

        var ds = wrapper.dataset || {};
        var urlStates = ds.urlInternalStates || '/api/internal/location/states';
        var urlDistricts = ds.urlInternalDistricts || '/api/internal/location/districts';
        var urlTalukas = ds.urlInternalTalukas || '/api/internal/location/talukas';
        var urlSuggestPost = ds.urlInternalSuggest || '/api/internal/location/suggest';

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

        function loadStates(countryParentId, selectedId) {
            var u = urlStates;
            if (countryParentId) {
                u = urlStates + (urlStates.indexOf('?') >= 0 ? '&' : '?') + 'parent_ids[]=' + encodeURIComponent(countryParentId);
            }
            fetch(u, { headers: { 'Accept': 'application/json' } })
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
            fetch(urlDistricts + '?parent_id=' + encodeURIComponent(stateId), { headers: { 'Accept': 'application/json' } })
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
            fetch(urlTalukas + '?parent_id=' + encodeURIComponent(districtId), { headers: { 'Accept': 'application/json' } })
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

        if (placeTypeSelect) {
            placeTypeSelect.value = 'village';
        }
        if (pincodeInput) {
            pincodeInput.value = '';
        }

        // Try to preselect from wrapper hidden fields / profile context if available
        var stateEl = wrapper.querySelector('.location-hidden-state')
            || wrapper.querySelector('.location-hidden-birth-state')
            || wrapper.querySelector('.location-hidden-native-state');
        var districtEl = wrapper.querySelector('.location-hidden-district')
            || wrapper.querySelector('.location-hidden-birth-district')
            || wrapper.querySelector('.location-hidden-native-district');
        var talukaEl = wrapper.querySelector('.location-hidden-taluka')
            || wrapper.querySelector('.location-hidden-birth-taluka')
            || wrapper.querySelector('.location-hidden-native-taluka');
        var defaultCountryId = wrapper.dataset.defaultCountryId || '';
        var defaultStateId = wrapper.dataset.defaultStateId || '';
        var stateHidden = (stateEl && stateEl.value) ? stateEl.value : '';
        var districtHidden = (districtEl && districtEl.value) ? districtEl.value : '';
        var talukaHidden = (talukaEl && talukaEl.value) ? talukaEl.value : '';
        var effectiveStateId = stateHidden || defaultStateId || '';
        loadStates(defaultCountryId, effectiveStateId || null);
        if (effectiveStateId) {
            loadDistricts(effectiveStateId, districtHidden || null);
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

            var placeType = (placeTypeSelect && placeTypeSelect.value) ? placeTypeSelect.value : 'village';
            var pinRaw = (pincodeInput && pincodeInput.value) ? String(pincodeInput.value).trim() : '';
            var payload = {
                suggested_name: name,
                state_id: stateId,
                district_id: districtId,
                taluka_id: talukaId,
                suggestion_type: placeType === 'city' ? 'city' : 'village',
            };
            if (pinRaw !== '') {
                payload.suggested_pincode = pinRaw;
            }

            fetch(urlSuggestPost, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(payload),
            }).then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        var pinDigits = pinRaw !== '' ? String(pinRaw).replace(/\D+/g, '') : '';
                        function selectedLabel(sel) {
                            if (!sel || !sel.options || sel.selectedIndex < 0) {
                                return '';
                            }
                            var o = sel.options[sel.selectedIndex];
                            return o ? String(o.text || '').trim() : '';
                        }
                        applyPendingPlaceFromModal(wrapper, {
                            placeName: name,
                            stateId: stateId,
                            districtId: districtId,
                            talukaId: talukaId,
                            stateName: selectedLabel(stateSelect),
                            districtName: selectedLabel(districtSelect),
                            talukaName: selectedLabel(talukaSelect),
                            pinDigits: pinDigits,
                            suggestionId: data.data && data.data.suggestion_id != null ? data.data.suggestion_id : null,
                        });
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

    /**
     * New place under a chosen state/district/taluka (admin review). Shown when query length >= 3,
     * including when other matches exist — same name can exist in different talukas.
     */
    function appendAddPlaceFormCta(wrapper, resultsEl, q) {
        if (!q || String(q).trim().length < 3) {
            return;
        }
        var name = String(q).trim();
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'w-full text-left px-3 py-2 text-sm bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-950/60 text-rose-700 dark:text-rose-300 border-t border-rose-200 dark:border-rose-800 location-suggest-btn';
        btn.setAttribute('data-suggest-name', name);
        btn.textContent = '+ Add this place (form)…';

        var hint = document.createElement('div');
        hint.className = 'px-3 py-1 text-xs text-gray-500 dark:text-gray-400';
        hint.textContent = 'Opens a short form: state, district, taluka, place type, optional pincode. Sent for admin review.';

        resultsEl.appendChild(btn);
        resultsEl.appendChild(hint);

        btn.addEventListener('click', function () {
            var inputText = btn.getAttribute('data-suggest-name') || name;
            resultsEl.classList.add('hidden');
            resultsEl.style.display = 'none';
            openSuggestModal(wrapper, inputText);
        });
    }

    function renderResults(wrapper, resultsEl, items, onSelect) {
        var q = (wrapper.querySelector('.location-typeahead-input').value || '').trim();
        resultsEl.innerHTML = '';

        if (!Array.isArray(items) || items.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'p-2 text-gray-500 dark:text-gray-400';
            empty.textContent = 'No matches';
            resultsEl.appendChild(empty);
        } else {
            items.forEach(function (item) {
                var div = document.createElement('div');
                div.className = 'p-2 border-b border-gray-200 dark:border-gray-600 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
                div.textContent = labelFromItem(item);
                div.addEventListener('click', function () {
                    onSelect(item, labelFromItem(item));
                });
                resultsEl.appendChild(div);
            });
        }

        appendAddPlaceFormCta(wrapper, resultsEl, q);

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

        function clearIntakeBirthPlaceText(wrapper) {
            if (wrapper.dataset.locationContext !== 'birth') return;
            var bp = document.getElementById('intake_birth_place_text');
            if (bp) bp.value = '';
        }

        function applyCanonicalSelection(item, displayLabel) {
            clearPendingSummary(wrapper);
            input.value = displayLabel;
            resultsEl.classList.add('hidden');
            resultsEl.style.display = 'none';

            var locationId = item.location_id || item.city_id || item.id || '';
            var cityId = item.city_id || item.id || '';
            var talukaId = item.taluka_id || '';
            var districtId = item.district_id || '';
            var stateId = item.state_id || '';
            var countryId = item.country_id || '';
            var locationHidden = wrapper.querySelector('.location-hidden-location-id');
            if (locationHidden) locationHidden.value = locationId;
            var locationInputHidden = wrapper.querySelector('.location-hidden-location-input');
            if (locationInputHidden) locationInputHidden.value = '';

            if (context === 'residence') {
                var country = wrapper.querySelector('.location-hidden-country');
                var state = wrapper.querySelector('.location-hidden-state');
                var district = wrapper.querySelector('.location-hidden-district');
                var taluka = wrapper.querySelector('.location-hidden-taluka');
                if (country) country.value = countryId;
                if (state) state.value = stateId;
                if (district) district.value = districtId;
                if (taluka) taluka.value = talukaId;
                wrapper.dataset.resCountryId = countryId || '';
                wrapper.dataset.resStateId = stateId || '';
                wrapper.dataset.resDistrictId = districtId || '';
                wrapper.dataset.resTalukaId = talukaId || '';
                wrapper.dataset.resLocationId = locationId || '';
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
                clearIntakeBirthPlaceText(wrapper);
            } else if (context === 'alliance') {
                var t = wrapper.querySelector('.location-hidden-taluka');
                var d = wrapper.querySelector('.location-hidden-district');
                var s = wrapper.querySelector('.location-hidden-state');
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

        function onSelect(item, displayLabel) {
            applyCanonicalSelection(item, displayLabel);
        }

        var gpsPanel = wrapper.querySelector('.location-gps-panel');
        var gpsBtn = wrapper.querySelector('.location-gps-btn');

        function hideGpsPanel() {
            if (!gpsPanel) return;
            gpsPanel.classList.add('hidden');
            gpsPanel.innerHTML = '';
        }

        function showGpsHtml(html) {
            if (!gpsPanel) return;
            gpsPanel.innerHTML = html;
            gpsPanel.classList.remove('hidden');
        }

        function itemFromResolvePayload(payload) {
            return {
                location_id: payload.location_id || payload.city_id || payload.id,
                city_id: payload.city_id,
                taluka_id: payload.taluka_id,
                district_id: payload.district_id,
                state_id: payload.state_id,
                country_id: payload.country_id,
            };
        }

        function bindGpsAssist() {
            if (!gpsBtn || !wrapper.dataset.resolveUrl) return;

            gpsBtn.addEventListener('click', function () {
                hideGpsPanel();
                showGpsHtml('<div class="text-gray-600 dark:text-gray-400 italic">Detecting your location…</div>');
                if (!navigator.geolocation) {
                    showGpsHtml('<div class="text-red-600 dark:text-red-400">Location is not supported in this browser.</div>');
                    return;
                }
                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        showGpsHtml('<div class="text-gray-600 dark:text-gray-400 italic">Finding nearest city…</div>');
                        fetch(wrapper.dataset.resolveUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken(),
                            },
                            body: JSON.stringify({
                                lat: pos.coords.latitude,
                                lon: pos.coords.longitude,
                            }),
                        })
                            .then(function (r) {
                                return r.json().then(function (data) {
                                    return { ok: r.ok, status: r.status, data: data };
                                });
                            })
                            .then(function (res) {
                                var data = res.data;
                                if (res.status === 429) {
                                    showGpsHtml('<div class="text-amber-700 dark:text-amber-300">Too many location requests. Wait a minute and try again.</div>');
                                    return;
                                }
                                if (data && data.status === 'busy') {
                                    showGpsHtml(
                                        '<div class="text-gray-600 dark:text-gray-400">Location service is busy. Please try again in a few seconds.</div>' +
                                            '<button type="button" class="location-gps-retry mt-2 text-sm text-indigo-600 hover:underline">Retry</button>'
                                    );
                                    var busyRetry = gpsPanel.querySelector('.location-gps-retry');
                                    if (busyRetry) {
                                        busyRetry.addEventListener('click', function () {
                                            gpsBtn.click();
                                        });
                                    }
                                    return;
                                }
                                if (!data || !data.success) {
                                    var msg = (data && data.message) ? data.message : 'Could not resolve location.';
                                    showGpsHtml(
                                        '<div class="text-red-600 dark:text-red-400">' +
                                            msg.replace(/</g, '&lt;') +
                                            '</div>' +
                                            '<button type="button" class="location-gps-retry mt-2 text-sm text-indigo-600 hover:underline">Retry</button>'
                                    );
                                    var retry = gpsPanel.querySelector('.location-gps-retry');
                                    if (retry) {
                                        retry.addEventListener('click', function () {
                                            gpsBtn.click();
                                        });
                                    }
                                    return;
                                }

                                var primaryItem = itemFromResolvePayload(data);
                                var alts = Array.isArray(data.alternatives) ? data.alternatives : [];
                                var altHtml = '';
                                alts.forEach(function (alt, i) {
                                    altHtml +=
                                        '<button type="button" class="location-gps-alt block w-full text-left mt-1 px-2 py-1 text-sm border border-gray-200 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700" data-alt-idx="' +
                                        i +
                                        '">' +
                                        labelFromItem(alt).replace(/</g, '&lt;') +
                                        '</button>';
                                });

                                showGpsHtml(
                                    '<div class="rounded border border-indigo-200 dark:border-indigo-800 bg-indigo-50/50 dark:bg-indigo-950/40 p-2 space-y-2">' +
                                        '<div class="text-sm text-gray-800 dark:text-gray-100">' +
                                            (data.display_label || '').replace(/</g, '&lt;') +
                                            '</div>' +
                                        '<div class="flex flex-wrap gap-2 pt-1">' +
                                        '<button type="button" class="location-gps-apply px-3 py-1.5 rounded text-xs font-semibold bg-indigo-600 text-white hover:bg-indigo-700">Use this location</button>' +
                                        '<button type="button" class="location-gps-dismiss px-3 py-1.5 rounded text-xs border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700">Dismiss</button>' +
                                        '</div>' +
                                        (alts.length ? '<div class="text-xs text-gray-600 dark:text-gray-400 pt-1">Other matches:</div>' + altHtml : '') +
                                        '</div>'
                                );

                                var applyBtn = gpsPanel.querySelector('.location-gps-apply');
                                if (applyBtn) {
                                    applyBtn.addEventListener('click', function () {
                                        applyCanonicalSelection(primaryItem, data.display_label || labelFromItem(primaryItem));
                                        hideGpsPanel();
                                    });
                                }
                                var dismissBtn = gpsPanel.querySelector('.location-gps-dismiss');
                                if (dismissBtn) {
                                    dismissBtn.addEventListener('click', function () {
                                        hideGpsPanel();
                                    });
                                }
                                gpsPanel.querySelectorAll('.location-gps-alt').forEach(function (btn) {
                                    btn.addEventListener('click', function () {
                                        var idx = parseInt(btn.getAttribute('data-alt-idx'), 10);
                                        var alt = alts[idx];
                                        if (!alt) return;
                                        applyCanonicalSelection(alt, alt.display_label || labelFromItem(alt));
                                        hideGpsPanel();
                                    });
                                });
                            })
                            .catch(function () {
                                showGpsHtml(
                                    '<div class="text-red-600">Network error.</div><button type="button" class="location-gps-retry mt-2 text-sm text-indigo-600 hover:underline">Retry</button>'
                                );
                                var retry2 = gpsPanel.querySelector('.location-gps-retry');
                                if (retry2) {
                                    retry2.addEventListener('click', function () {
                                        gpsBtn.click();
                                    });
                                }
                            });
                    },
                    function () {
                        showGpsHtml(
                            '<div class="text-red-600 dark:text-red-400">Location permission denied or unavailable.</div>' +
                                '<button type="button" class="location-gps-retry mt-2 text-sm text-indigo-600 hover:underline">Retry</button>'
                        );
                        var retry3 = gpsPanel.querySelector('.location-gps-retry');
                        if (retry3) {
                            retry3.addEventListener('click', function () {
                                gpsBtn.click();
                            });
                        }
                    },
                    { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 }
                );
            });
        }

        bindGpsAssist();

        var debounce = null;
        input.addEventListener('input', function () {
            clearTimeout(debounce);
            clearPendingSummary(wrapper);
            var q = input.value.trim();
            clearCanonicalSelection(wrapper);
            var locationInputHidden = wrapper.querySelector('.location-hidden-location-input');
            if (locationInputHidden) locationInputHidden.value = q;
            if (q.length < MIN_SEARCH_CHARS) {
                resultsEl.classList.add('hidden');
                resultsEl.style.display = 'none';
                return;
            }
            debounce = setTimeout(function () {
                fetch(locationSearchRequestUrl(wrapper, q, APP_LOCALE), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) {
                        if (!r.ok) {
                            throw new Error('search HTTP ' + r.status);
                        }
                        return r.json();
                    })
                    .then(function (items) {
                        if (!Array.isArray(items)) {
                            items = [];
                        }
                        renderResults(wrapper, resultsEl, items, onSelect);
                    })
                    .catch(function () {
                        renderResults(wrapper, resultsEl, [], onSelect);
                    });
            }, DEBOUNCE_MS);
        });
        input.addEventListener('focus', function () {
            if (input.value.trim().length >= MIN_SEARCH_CHARS && resultsEl.innerHTML) {
                resultsEl.classList.remove('hidden');
                resultsEl.style.display = 'block';
            }
        });
        document.addEventListener('click', function (e) {
            if (wrapper.contains(e.target)) return;
            resultsEl.classList.add('hidden');
            resultsEl.style.display = 'none';
            hideGpsPanel();
        });

        if (context === 'residence') {
            var hc = wrapper.querySelector('.location-hidden-country');
            var hs = wrapper.querySelector('.location-hidden-state');
            var hd = wrapper.querySelector('.location-hidden-district');
            var ht = wrapper.querySelector('.location-hidden-taluka');
            var hlid = wrapper.querySelector('.location-hidden-location-id');
            if (hc && hc.value) wrapper.dataset.resCountryId = hc.value;
            if (hs && hs.value) wrapper.dataset.resStateId = hs.value;
            if (hd && hd.value) wrapper.dataset.resDistrictId = hd.value;
            if (ht && ht.value) wrapper.dataset.resTalukaId = ht.value;
            if (hlid && hlid.value) wrapper.dataset.resLocationId = hlid.value;
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
            var lid = wrapper.querySelector('.location-hidden-location-id');
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
            if (lid && !String(lid.value || '').trim() && wrapper.dataset.resLocationId) {
                lid.value = wrapper.dataset.resLocationId;
            }
        });
    }

    function init() {
        document.querySelectorAll('.location-typeahead-wrapper').forEach(bindWrapper);
    }

    document.addEventListener('submit', function (e) {
        var t = e.target;
        if (!t || t.tagName !== 'FORM') return;
        var invalidSelection = false;
        t.querySelectorAll('.location-typeahead-wrapper').forEach(function (wrapper) {
            var vis = wrapper.querySelector('.location-typeahead-input');
            var locationIdEl = wrapper.querySelector('.location-hidden-location-id');
            var locationInputEl = wrapper.querySelector('.location-hidden-location-input');
            if (!vis || !locationIdEl || !locationInputEl) return;

            var typed = (vis.value || '').trim();
            var locationId = (locationIdEl.value || '').trim();
            var locationInput = (locationInputEl.value || '').trim();
            if (typed !== '' && locationId === '' && locationInput === '') {
                invalidSelection = true;
            }
        });
        if (invalidSelection) {
            e.preventDefault();
            window.alert('Please select a location from suggestions');
            return;
        }
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
