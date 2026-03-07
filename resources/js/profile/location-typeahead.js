/**
 * Reusable location/address typeahead — single component, used in wizard (residence/work/native)
 * and alliance rows. Uses /api/internal/location/search.
 */
(function () {
    'use strict';

    var SEARCH_URL = '/api/internal/location/search';
    var DEBOUNCE_MS = 200;

    function buildLine(item) {
        var cityName = item.city_name || item.label || item.name || '';
        return cityName + ', ' + (item.taluka_name || '') + ', ' + (item.district_name || '') + ', ' + (item.state_name || '');
    }

    function renderResults(resultsEl, data, onSelect) {
        if (!data.success || !data.data || data.data.length === 0) {
            resultsEl.innerHTML = '<div class="p-2 text-gray-500">No matches</div>';
            resultsEl.classList.remove('hidden');
            resultsEl.style.display = 'block';
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
                fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        renderResults(resultsEl, data, onSelect);
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
    }

    function init() {
        document.querySelectorAll('.location-typeahead-wrapper').forEach(bindWrapper);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.LocationTypeahead = { init: init };
})();
