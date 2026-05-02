@extends('layouts.admin')

@section('content')
<div class="max-w-lg mx-auto bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Merge locations</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">Moves profile references, pincodes, and aliases from the source row onto the target, then deletes the source. Children of the source are reparented to the target when names do not clash.</p>

    <form id="merge-form" class="space-y-4">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Source location ID (duplicate — will be removed)</label>
            <input type="number" id="source_id" required min="1" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm" />
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Target location ID (canonical — kept)</label>
            <input type="number" id="target_id" required min="1" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm" />
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-medium">Merge</button>
    </form>
    <p id="merge-msg" class="mt-4 text-sm text-gray-700 dark:text-gray-300"></p>
    <p class="mt-6"><a href="{{ route('admin.locations.index') }}" class="text-indigo-600 dark:text-indigo-400 text-sm">← Locations list</a></p>
</div>

<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    document.getElementById('merge-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var sid = parseInt(document.getElementById('source_id').value, 10);
        var tid = parseInt(document.getElementById('target_id').value, 10);
        document.getElementById('merge-msg').textContent = 'Working…';
        fetch('/admin/internal/locations/' + sid + '/merge', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ target_location_id: tid })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                document.getElementById('merge-msg').textContent = x.j.message || (x.ok ? 'Done.' : 'Failed');
            });
    });
})();
</script>
@endsection
