@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Canonical locations</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Unified <code class="text-xs">locations</code> master data — usage-weighted list, edit, merge duplicates. Open-place suggestion queue remains <a href="{{ route('admin.open-place-suggestions.index') }}" class="text-indigo-600 dark:text-indigo-400 underline">here</a>.</p>

        <div class="flex flex-wrap gap-4 items-end mb-4">
            <div class="flex-1 min-w-[200px]">
                <label for="f-q" class="block text-xs text-gray-500 mb-1">Search name / slug</label>
                <input type="text" id="f-q" class="w-full rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm" placeholder="Substring…" />
            </div>
            <button type="button" id="btn-load" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-900">Load</button>
            <a href="{{ route('admin.locations.merge') }}" class="px-4 py-2 rounded-lg text-sm font-medium bg-amber-600 text-white">Merge tool</a>
        </div>

        <div id="fetch-error" class="hidden mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800"></div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[720px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-3 text-sm font-semibold text-gray-700 dark:text-gray-300">ID</th>
                        <th class="text-left py-3 px-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Name</th>
                        <th class="text-left py-3 px-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Type</th>
                        <th class="text-left py-3 px-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Category</th>
                        <th class="text-left py-3 px-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Usage</th>
                        <th class="text-left py-3 px-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                    </tr>
                </thead>
                <tbody id="loc-tbody">
                    <tr><td colspan="6" class="py-6 px-4 text-center text-gray-500">Click Load to fetch.</td></tr>
                </tbody>
            </table>
        </div>
        <div id="pagination" class="px-2 py-4 border-t border-gray-200 dark:border-gray-600"></div>
    </div>
</div>

@php
    $adminGenericErr = \App\Support\ErrorFactory::generic()->message;
    $adminNetworkErr = \App\Support\ErrorFactory::helpCentreNetwork()->message;
@endphp

<script>
(function () {
    var INTERNAL = '/admin/internal/locations';
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var token = csrf ? csrf.getAttribute('content') : '';
    var tbody = document.getElementById('loc-tbody');
    var errEl = document.getElementById('fetch-error');
    var pagEl = document.getElementById('pagination');

    function escapeHtml(t) {
        if (t == null) return '';
        var d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    function loadUrl(url) {
        errEl.classList.add('hidden');
        tbody.innerHTML = '<tr><td colspan="6" class="py-6 text-center text-gray-500">Loading…</td></tr>';
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.ok || !x.j.success) {
                    errEl.textContent = (x.j && x.j.message) ? x.j.message : @json($adminGenericErr);
                    errEl.classList.remove('hidden');
                    return;
                }
                var payload = x.j.data || {};
                var rows = payload.data || [];
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="6" class="py-6 text-center text-gray-500">No rows.</td></tr>';
                } else {
                    tbody.innerHTML = rows.map(function (item) {
                        var u = item.usage_stat && item.usage_stat.usage_count != null ? item.usage_stat.usage_count : '—';
                        return '<tr class="border-b border-gray-100 dark:border-gray-600">' +
                            '<td class="py-2 px-3 text-sm">' + item.id + '</td>' +
                            '<td class="py-2 px-3 text-sm">' + escapeHtml(item.name) + '</td>' +
                            '<td class="py-2 px-3 text-sm">' + escapeHtml(item.type) + '</td>' +
                            '<td class="py-2 px-3 text-sm">' + escapeHtml(item.category || '—') + '</td>' +
                            '<td class="py-2 px-3 text-sm">' + escapeHtml(String(u)) + '</td>' +
                            '<td class="py-2 px-3 text-sm whitespace-nowrap">' +
                            '<a class="text-indigo-600 dark:text-indigo-400 text-sm mr-2" href="/admin/locations/' + item.id + '/edit">Edit</a>' +
                            '</td></tr>';
                    }).join('');
                }
                var links = payload.links || [];
                if (links.length) {
                    pagEl.innerHTML = '<div class="flex flex-wrap gap-2">' + links.map(function (link) {
                        var label = String(link.label).replace(/&laquo;|Previous/g, 'Prev').replace(/&raquo;|Next/g, 'Next');
                        var cls = link.active ? 'px-3 py-1 rounded bg-indigo-600 text-white text-sm' : 'px-3 py-1 rounded bg-gray-200 dark:bg-gray-600 text-sm';
                        var href = link.url || '#';
                        return '<a href="#" class="page-link ' + cls + '" data-url="' + escapeHtml(href) + '">' + escapeHtml(label) + '</a>';
                    }).join('') + '</div>';
                    pagEl.querySelectorAll('.page-link').forEach(function (a) {
                        a.addEventListener('click', function (e) {
                            e.preventDefault();
                            var u = this.getAttribute('data-url');
                            if (u && u !== '#') loadUrl(u);
                        });
                    });
                } else {
                    pagEl.innerHTML = '';
                }
            })
            .catch(function () {
                errEl.textContent = @json($adminNetworkErr);
                errEl.classList.remove('hidden');
            });
    }

    document.getElementById('btn-load').addEventListener('click', function () {
        var q = document.getElementById('f-q').value.trim();
        var p = new URLSearchParams();
        if (q) p.set('q', q);
        p.set('per_page', '25');
        loadUrl(INTERNAL + '?' + p.toString());
    });
})();
</script>
@endsection
