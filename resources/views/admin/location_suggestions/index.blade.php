@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Location Suggestions</h1>

        <div class="flex gap-2 mb-4">
            <button type="button" class="filter-btn px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white" data-status="pending">Pending</button>
            <button type="button" class="filter-btn px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300" data-status="approved">Approved</button>
            <button type="button" class="filter-btn px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300" data-status="rejected">Rejected</button>
        </div>

        <div id="fetch-error" class="hidden mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800"></div>
        <div id="loading" class="hidden mb-4 text-gray-500">Loading…</div>

        <table class="w-full border-collapse">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">ID</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Suggested Name</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Type</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Taluka</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">District</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">State</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Suggested By</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Status</th>
                    <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                </tr>
            </thead>
            <tbody id="location-suggestions-tbody">
                <tr>
                    <td colspan="9" class="py-8 px-4 text-center text-gray-500">Loading…</td>
                </tr>
            </tbody>
        </table>

        <div id="pagination" class="px-6 py-4 border-t border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/30"></div>
    </div>
</div>

<script>
(function () {
    var currentStatus = 'pending';
    var csrfToken = document.querySelector('meta[name="csrf-token"]') && document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var tbody = document.getElementById('location-suggestions-tbody');
    var fetchError = document.getElementById('fetch-error');
    var loadingEl = document.getElementById('loading');
    var paginationEl = document.getElementById('pagination');

    function setActiveFilter(status) {
        document.querySelectorAll('.filter-btn').forEach(function (btn) {
            var s = btn.getAttribute('data-status');
            if (s === status) {
                btn.className = 'filter-btn px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white';
            } else {
                btn.className = 'filter-btn px-4 py-2 rounded-lg text-sm font-medium bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300';
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
        var taluka = item.taluka ? escapeHtml(item.taluka.name) : '—';
        var district = item.district ? escapeHtml(item.district.name) : '—';
        var state = item.state ? escapeHtml(item.state.name) : '—';
        var suggestedBy = item.suggested_by ? escapeHtml(item.suggested_by.name || item.suggested_by.email || item.suggested_by.id) : '—';
        var status = escapeHtml(item.status);
        var type = escapeHtml(item.suggestion_type || '—');
        var name = escapeHtml(item.suggested_name);

        var actions = '';
        if (item.status === 'pending') {
            actions = '<button type="button" class="action-approve px-2 py-1 rounded text-sm font-medium bg-emerald-600 text-white mr-1" data-id="' + item.id + '">Approve</button>' +
                '<button type="button" class="action-reject px-2 py-1 rounded text-sm font-medium bg-red-600 text-white" data-id="' + item.id + '">Reject</button>';
        } else {
            actions = '—';
        }

        return '<tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">' +
            '<td class="py-3 px-4">' + item.id + '</td>' +
            '<td class="py-3 px-4">' + name + '</td>' +
            '<td class="py-3 px-4">' + type + '</td>' +
            '<td class="py-3 px-4">' + taluka + '</td>' +
            '<td class="py-3 px-4">' + district + '</td>' +
            '<td class="py-3 px-4">' + state + '</td>' +
            '<td class="py-3 px-4">' + suggestedBy + '</td>' +
            '<td class="py-3 px-4">' + status + '</td>' +
            '<td class="py-3 px-4">' + actions + '</td>' +
            '</tr>';
    }

    function renderPagination(data) {
        var links = data && data.links;
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
            if (url !== '#') {
                if (url.indexOf('status=') === -1) {
                    url += (url.indexOf('?') !== -1 ? '&' : '?') + 'status=' + encodeURIComponent(currentStatus);
                }
                html += '<a href="' + escapeHtml(url) + '" class="pagination-link ' + cls + '" data-url="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>';
            } else {
                html += '<span class="' + cls + '">' + escapeHtml(label) + '</span>';
            }
        });
        html += '</div>';
        paginationEl.innerHTML = html;

        paginationEl.querySelectorAll('.pagination-link').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var url = this.getAttribute('data-url');
                if (url) loadByUrl(url);
            });
        });
    }

    function loadByUrl(url) {
        fetchError.classList.add('hidden');
        loadingEl.classList.remove('hidden');
        tbody.innerHTML = '<tr><td colspan="9" class="py-8 px-4 text-center text-gray-500">Loading…</td></tr>';

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                loadingEl.classList.add('hidden');
                if (!result.ok || !result.data.success) {
                    fetchError.textContent = result.data && result.data.message ? result.data.message : 'Failed to load suggestions.';
                    fetchError.classList.remove('hidden');
                    tbody.innerHTML = '<tr><td colspan="9" class="py-8 px-4 text-center text-gray-500">—</td></tr>';
                    return;
                }
                var payload = result.data.data || {};
                var items = Array.isArray(payload.data) ? payload.data : [];
                tbody.innerHTML = items.length ? items.map(renderRow).join('') : '<tr><td colspan="9" class="py-8 px-4 text-center text-gray-500">No suggestions.</td></tr>';
                renderPagination(payload);
            })
            .catch(function () {
                loadingEl.classList.add('hidden');
                fetchError.textContent = 'Network error. Please try again.';
                fetchError.classList.remove('hidden');
                tbody.innerHTML = '<tr><td colspan="9" class="py-8 px-4 text-center text-gray-500">—</td></tr>';
            });
    }

    function loadData(status) {
        currentStatus = status;
        setActiveFilter(status);
        var url = '/admin/internal/location-suggestions?status=' + encodeURIComponent(status);
        loadByUrl(url);
    }

    function postAction(id, action) {
        var path = '/admin/internal/location-suggestions/' + id + '/' + action;
        var opts = {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken || ''
            }
        };
        fetch(path, opts)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    loadData(currentStatus);
                } else {
                    alert(data && data.message ? data.message : 'Action failed.');
                }
            })
            .catch(function () {
                alert('Request failed. Please try again.');
            });
    }

    document.querySelectorAll('.filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            loadData(this.getAttribute('data-status'));
        });
    });

    tbody.addEventListener('click', function (e) {
        var approve = e.target.classList.contains('action-approve');
        var reject = e.target.classList.contains('action-reject');
        if (approve || reject) {
            var id = e.target.getAttribute('data-id');
            if (id) postAction(id, approve ? 'approve' : 'reject');
        }
    });

    loadData('pending');
})();
</script>
@endsection
