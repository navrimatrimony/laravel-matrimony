@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Open place suggestions</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">Free-text locations from intake → review → city or alias.</p>

        <div class="flex flex-wrap gap-2 mb-3">
            <button type="button" class="filter-status px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white" data-status="pending">Pending</button>
            <button type="button" class="filter-status px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300" data-status="auto_candidate">Auto candidate</button>
            <button type="button" class="filter-status px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300" data-status="approved">Approved</button>
            <button type="button" class="filter-status px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300" data-status="rejected">Rejected</button>
            <button type="button" class="filter-status px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300" data-status="merged">Merged</button>
        </div>

        <div class="flex flex-wrap gap-4 items-end mb-4">
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" id="f-unresolved" class="rounded border-gray-300" />
                Unresolved only
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" id="f-highpri" class="rounded border-gray-300" />
                High priority (usage ≥ 5)
            </label>
            <div>
                <label for="f-min-usage" class="block text-xs text-gray-500 mb-1">Min usage</label>
                <input type="number" id="f-min-usage" min="0" class="w-24 rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm" placeholder="0" />
            </div>
            <div class="flex-1 min-w-[200px]">
                <label for="f-q" class="block text-xs text-gray-500 mb-1">Search raw text</label>
                <input type="text" id="f-q" class="w-full rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm" placeholder="Substring…" />
            </div>
            <button type="button" id="btn-apply-filters" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-900">Apply</button>
        </div>

        <div id="fetch-error" class="hidden mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800"></div>
        <div id="loading" class="hidden mb-4 text-gray-500">Loading…</div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[960px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">ID</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">Raw</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">Normalized</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">Usage</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">Status</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">City</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">Match</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">Created</th>
                        <th class="text-left py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 text-sm">Actions</th>
                    </tr>
                </thead>
                <tbody id="ops-tbody">
                    <tr>
                        <td colspan="9" class="py-8 px-4 text-center text-gray-500">Loading…</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="pagination" class="px-2 py-4 border-t border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/30"></div>
    </div>
</div>

{{-- Approve as new city --}}
<div id="modal-approve" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full p-6">
        <h2 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">Approve as new city</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Suggestion <span id="approve-sid"></span> — pick taluka (district must match taluka).</p>
        <input type="hidden" id="approve-id" />
        <div class="space-y-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">State</label>
                <select id="ap-state" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm"></select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">District</label>
                <select id="ap-district" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm" disabled></select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Taluka</label>
                <select id="ap-taluka" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm" disabled></select>
            </div>
        </div>
        <div class="flex justify-end gap-2 mt-6">
            <button type="button" class="modal-close px-4 py-2 rounded-lg text-sm bg-gray-200 dark:bg-gray-600">Cancel</button>
            <button type="button" id="btn-approve-submit" class="px-4 py-2 rounded-lg text-sm bg-emerald-600 text-white">Create city</button>
        </div>
    </div>
</div>

{{-- Map to existing --}}
<div id="modal-map" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full p-6">
        <h2 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">Map to existing city</h2>
        <input type="hidden" id="map-id" />
        <p class="text-sm mb-2 text-gray-600 dark:text-gray-400">Search by city name (min 2 chars).</p>
        <input type="text" id="map-q" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm mb-2" placeholder="Type to search…" autocomplete="off" />
        <div id="map-results" class="max-h-48 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded text-sm mb-4"></div>
        <input type="hidden" id="map-city-id" />
        <p id="map-picked" class="text-sm text-gray-700 dark:text-gray-300 mb-4"></p>
        <div class="flex justify-end gap-2">
            <button type="button" class="modal-close px-4 py-2 rounded-lg text-sm bg-gray-200 dark:bg-gray-600">Cancel</button>
            <button type="button" id="btn-map-submit" class="px-4 py-2 rounded-lg text-sm bg-indigo-600 text-white">Approve map</button>
        </div>
    </div>
</div>

{{-- Merge --}}
<div id="modal-merge" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
        <h2 class="text-lg font-semibold mb-4 text-gray-900 dark:text-gray-100">Merge into target</h2>
        <input type="hidden" id="merge-source-id" />
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Source ID <span id="merge-sid"></span> — usage will be added to target (both must be pending, unresolved).</p>
        <label class="block text-xs text-gray-500 mb-1">Target suggestion ID</label>
        <input type="number" id="merge-target-id" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm mb-4" min="1" />
        <div class="flex justify-end gap-2">
            <button type="button" class="modal-close px-4 py-2 rounded-lg text-sm bg-gray-200 dark:bg-gray-600">Cancel</button>
            <button type="button" id="btn-merge-submit" class="px-4 py-2 rounded-lg text-sm bg-amber-600 text-white">Merge</button>
        </div>
    </div>
</div>

@php
    $adminGenericErr = \App\Support\ErrorFactory::generic()->message;
    $adminNetworkErr = \App\Support\ErrorFactory::helpCentreNetwork()->message;
@endphp

<script>
(function () {
    var currentStatus = 'pending';
    var csrfToken = document.querySelector('meta[name="csrf-token"]') && document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var tbody = document.getElementById('ops-tbody');
    var fetchError = document.getElementById('fetch-error');
    var loadingEl = document.getElementById('loading');
    var paginationEl = document.getElementById('pagination');
    var GENERIC_ERR = @json($adminGenericErr);
    var NETWORK_ERR = @json($adminNetworkErr);
    var LOC_API = @json(url('/api/internal/location'));
    var INTERNAL = '/admin/internal/open-place-suggestions';
    var mapSearchTimer = null;

    function toastErr(msg) {
        var m = msg || GENERIC_ERR;
        if (window.toastr && typeof window.toastr.error === 'function') {
            window.toastr.error(m);
        } else if (m) {
            window.alert(m);
        }
    }

    function toastOk(msg) {
        if (window.toastr && typeof window.toastr.success === 'function') {
            window.toastr.success(msg);
        }
    }

    function buildQueryString() {
        var p = new URLSearchParams();
        p.set('status', currentStatus);
        if (document.getElementById('f-unresolved').checked) {
            p.set('unresolved_only', '1');
        }
        if (document.getElementById('f-highpri').checked) {
            p.set('high_priority', '1');
        }
        var minu = document.getElementById('f-min-usage').value.trim();
        if (minu !== '') {
            p.set('min_usage', minu);
        }
        var q = document.getElementById('f-q').value.trim();
        if (q !== '') {
            p.set('q', q);
        }
        p.set('per_page', '25');
        return p.toString();
    }

    function setActiveStatusButtons() {
        document.querySelectorAll('.filter-status').forEach(function (btn) {
            var s = btn.getAttribute('data-status');
            if (s === currentStatus) {
                btn.className = 'filter-status px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white';
            } else {
                btn.className = 'filter-status px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300';
            }
        });
    }

    function escapeHtml(text) {
        if (text == null) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderRow(item) {
        var raw = escapeHtml(item.raw_input);
        var norm = escapeHtml(item.normalized_input);
        var usage = escapeHtml(String(item.usage_count));
        var st = escapeHtml(item.status);
        var statusLabel = st;
        if (item.status === 'auto_candidate') {
            statusLabel = 'auto_candidate (Auto suggested — approve?)';
        }
        var city = item.resolved_city_id ? ('#' + item.resolved_city_id + (item.resolved_city && item.resolved_city.name ? ' ' + escapeHtml(item.resolved_city.name) : '')) : '—';
        var mt = escapeHtml(item.match_type || '—');
        var created = item.created_at ? escapeHtml(item.created_at.replace('T', ' ').slice(0, 19)) : '—';
        var actions = '—';
        if ((item.status === 'pending' || item.status === 'auto_candidate') && !item.merged_into_suggestion_id) {
            actions = '<button type="button" class="op-appr px-2 py-1 rounded text-xs font-medium bg-emerald-600 text-white mr-1" data-id="' + item.id + '">New city</button>' +
                '<button type="button" class="op-map px-2 py-1 rounded text-xs font-medium bg-indigo-600 text-white mr-1" data-id="' + item.id + '">Map</button>' +
                '<button type="button" class="op-merge px-2 py-1 rounded text-xs font-medium bg-amber-600 text-white mr-1" data-id="' + item.id + '">Merge</button>' +
                '<button type="button" class="op-rej px-2 py-1 rounded text-xs font-medium bg-red-600 text-white" data-id="' + item.id + '">Reject</button>';
        }
        return '<tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">' +
            '<td class="py-2 px-3 text-sm">' + item.id + '</td>' +
            '<td class="py-2 px-3 text-sm max-w-[200px] break-words">' + raw + '</td>' +
            '<td class="py-2 px-3 text-sm">' + norm + '</td>' +
            '<td class="py-2 px-3 text-sm">' + usage + '</td>' +
            '<td class="py-2 px-3 text-sm">' + statusLabel + '</td>' +
            '<td class="py-2 px-3 text-sm">' + city + '</td>' +
            '<td class="py-2 px-3 text-sm">' + mt + '</td>' +
            '<td class="py-2 px-3 text-sm whitespace-nowrap">' + created + '</td>' +
            '<td class="py-2 px-3 text-sm whitespace-nowrap">' + actions + '</td>' +
            '</tr>';
    }

    function renderPagination(payload) {
        var links = payload && payload.links;
        if (!links || !links.length) {
            paginationEl.innerHTML = '';
            return;
        }
        var html = '<div class="flex flex-wrap gap-2 items-center">';
        links.forEach(function (link) {
            var label = link.label;
            if (label.indexOf('&laquo;') !== -1) label = 'Prev';
            if (label.indexOf('&raquo;') !== -1) label = 'Next';
            var cls = link.active ? 'px-3 py-1 rounded bg-indigo-600 text-white text-sm' : 'px-3 py-1 rounded bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 text-sm hover:bg-gray-300';
            var url = link.url || '#';
            html += '<a href="#" class="pagination-link ' + cls + '" data-url="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>';
        });
        html += '</div>';
        paginationEl.innerHTML = html;
        paginationEl.querySelectorAll('.pagination-link').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var url = this.getAttribute('data-url');
                if (url && url !== '#') {
                    loadByUrl(url);
                }
            });
        });
    }

    function loadByUrl(url) {
        fetchError.classList.add('hidden');
        loadingEl.classList.remove('hidden');
        tbody.innerHTML = '<tr><td colspan="9" class="py-8 px-4 text-center text-gray-500">Loading…</td></tr>';

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                loadingEl.classList.add('hidden');
                if (!result.ok || !result.data.success) {
                    fetchError.textContent = (result.data && result.data.message) ? result.data.message : GENERIC_ERR;
                    fetchError.classList.remove('hidden');
                    tbody.innerHTML = '<tr><td colspan="9" class="py-8 px-4 text-center text-gray-500">—</td></tr>';
                    return;
                }
                var payload = result.data.data || {};
                var items = Array.isArray(payload.data) ? payload.data : [];
                tbody.innerHTML = items.length ? items.map(renderRow).join('') : '<tr><td colspan="9" class="py-8 px-4 text-center text-gray-500">No rows.</td></tr>';
                renderPagination(payload);
            })
            .catch(function () {
                loadingEl.classList.add('hidden');
                fetchError.textContent = NETWORK_ERR;
                fetchError.classList.remove('hidden');
            });
    }

    function loadData() {
        setActiveStatusButtons();
        var url = INTERNAL + '?' + buildQueryString();
        loadByUrl(url);
    }

    function postJson(path, body) {
        return fetch(path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body ? JSON.stringify(body) : '{}'
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
        });
    }

    function hideModals() {
        document.querySelectorAll('#modal-approve, #modal-map, #modal-merge').forEach(function (el) { el.classList.add('hidden'); });
    }

    document.querySelectorAll('.modal-close').forEach(function (b) {
        b.addEventListener('click', hideModals);
    });

    document.querySelectorAll('.filter-status').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentStatus = this.getAttribute('data-status');
            loadData();
        });
    });

    document.getElementById('btn-apply-filters').addEventListener('click', loadData);

    tbody.addEventListener('click', function (e) {
        var t = e.target;
        if (t.classList.contains('op-rej')) {
            var id = t.getAttribute('data-id');
            if (!id || !confirm('Reject suggestion #' + id + '?')) return;
            postJson(INTERNAL + '/' + id + '/reject', {}).then(function (r) {
                if (r.data && r.data.success) {
                    toastOk(r.data.message || 'Rejected');
                    loadData();
                } else {
                    toastErr(r.data && r.data.message);
                }
            }).catch(function () { toastErr(NETWORK_ERR); });
        }
        if (t.classList.contains('op-appr')) {
            openApproveModal(t.getAttribute('data-id'));
        }
        if (t.classList.contains('op-map')) {
            openMapModal(t.getAttribute('data-id'));
        }
        if (t.classList.contains('op-merge')) {
            openMergeModal(t.getAttribute('data-id'));
        }
    });

    function openApproveModal(id) {
        document.getElementById('approve-id').value = id;
        document.getElementById('approve-sid').textContent = '#' + id;
        document.getElementById('ap-state').innerHTML = '';
        document.getElementById('ap-district').innerHTML = '';
        document.getElementById('ap-taluka').innerHTML = '';
        document.getElementById('ap-district').disabled = true;
        document.getElementById('ap-taluka').disabled = true;
        document.getElementById('modal-approve').classList.remove('hidden');
        fetch(LOC_API + '/states', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var sel = document.getElementById('ap-state');
                (j.data || []).forEach(function (s) {
                    var o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = s.name;
                    sel.appendChild(o);
                });
            })
            .catch(function () { toastErr(NETWORK_ERR); });
    }

    document.getElementById('ap-state').addEventListener('change', function () {
        var sid = this.value;
        var dsel = document.getElementById('ap-district');
        var tsel = document.getElementById('ap-taluka');
        dsel.innerHTML = '';
        tsel.innerHTML = '';
        tsel.disabled = true;
        if (!sid) {
            dsel.disabled = true;
            return;
        }
        dsel.disabled = false;
        fetch(LOC_API + '/districts?state_id=' + encodeURIComponent(sid), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                (j.data || []).forEach(function (d) {
                    var o = document.createElement('option');
                    o.value = d.id;
                    o.textContent = d.name;
                    dsel.appendChild(o);
                });
                dsel.dispatchEvent(new Event('change'));
            });
    });

    document.getElementById('ap-district').addEventListener('change', function () {
        var did = this.value;
        var tsel = document.getElementById('ap-taluka');
        tsel.innerHTML = '';
        if (!did) {
            tsel.disabled = true;
            return;
        }
        tsel.disabled = false;
        fetch(LOC_API + '/talukas?district_id=' + encodeURIComponent(did), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                (j.data || []).forEach(function (t) {
                    var o = document.createElement('option');
                    o.value = t.id;
                    o.textContent = t.name;
                    tsel.appendChild(o);
                });
            });
    });

    document.getElementById('btn-approve-submit').addEventListener('click', function () {
        var id = document.getElementById('approve-id').value;
        var tid = document.getElementById('ap-taluka').value;
        var did = document.getElementById('ap-district').value;
        if (!tid) {
            toastErr('Select taluka.');
            return;
        }
        postJson(INTERNAL + '/' + id + '/approve-as-city', { taluka_id: parseInt(tid, 10), district_id: did ? parseInt(did, 10) : null })
            .then(function (r) {
                if (r.data && r.data.success) {
                    toastOk(r.data.message || 'Approved');
                    hideModals();
                    loadData();
                } else {
                    toastErr(r.data && r.data.message);
                }
            })
            .catch(function () { toastErr(NETWORK_ERR); });
    });

    function openMapModal(id) {
        document.getElementById('map-id').value = id;
        document.getElementById('map-q').value = '';
        document.getElementById('map-results').innerHTML = '';
        document.getElementById('map-city-id').value = '';
        document.getElementById('map-picked').textContent = '';
        document.getElementById('modal-map').classList.remove('hidden');
    }

    document.getElementById('map-q').addEventListener('input', function () {
        var q = this.value.trim();
        clearTimeout(mapSearchTimer);
        var box = document.getElementById('map-results');
        if (q.length < 2) {
            box.innerHTML = '';
            return;
        }
        mapSearchTimer = setTimeout(function () {
            fetch(INTERNAL + '/city-search?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    var rows = j.data || [];
                    box.innerHTML = rows.map(function (c) {
                        var label = '#' + c.id + ' ' + c.name + (c.district ? ' — ' + c.district : '') + (c.state ? ', ' + c.state : '');
                        return '<button type="button" class="map-pick w-full text-left px-2 py-1 hover:bg-gray-100 dark:hover:bg-gray-700 text-sm" data-id="' + c.id + '">' + escapeHtml(label) + '</button>';
                    }).join('') || '<div class="p-2 text-gray-500 text-sm">No matches.</div>';
                    box.querySelectorAll('.map-pick').forEach(function (b) {
                        b.addEventListener('click', function () {
                            document.getElementById('map-city-id').value = this.getAttribute('data-id');
                            document.getElementById('map-picked').textContent = 'Selected: ' + this.textContent;
                        });
                    });
                });
        }, 300);
    });

    document.getElementById('btn-map-submit').addEventListener('click', function () {
        var id = document.getElementById('map-id').value;
        var cid = document.getElementById('map-city-id').value;
        if (!cid) {
            toastErr('Pick a city from search results.');
            return;
        }
        postJson(INTERNAL + '/' + id + '/map-to-city', { city_id: parseInt(cid, 10) })
            .then(function (r) {
                if (r.data && r.data.success) {
                    toastOk(r.data.message || 'Mapped');
                    hideModals();
                    loadData();
                } else {
                    toastErr(r.data && r.data.message);
                }
            })
            .catch(function () { toastErr(NETWORK_ERR); });
    });

    function openMergeModal(id) {
        document.getElementById('merge-source-id').value = id;
        document.getElementById('merge-sid').textContent = '#' + id;
        document.getElementById('merge-target-id').value = '';
        document.getElementById('modal-merge').classList.remove('hidden');
    }

    document.getElementById('btn-merge-submit').addEventListener('click', function () {
        var sid = document.getElementById('merge-source-id').value;
        var tid = document.getElementById('merge-target-id').value;
        if (!tid) {
            toastErr('Enter target ID.');
            return;
        }
        postJson(INTERNAL + '/' + sid + '/merge', { target_id: parseInt(tid, 10) })
            .then(function (r) {
                if (r.data && r.data.success) {
                    toastOk(r.data.message || 'Merged');
                    hideModals();
                    loadData();
                } else {
                    toastErr(r.data && r.data.message);
                }
            })
            .catch(function () { toastErr(NETWORK_ERR); });
    });

    loadData();
})();
</script>
@endsection
