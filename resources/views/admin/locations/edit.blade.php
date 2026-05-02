@extends('layouts.admin')

@section('content')
<div class="max-w-2xl mx-auto bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">Edit location #{{ $location->id }}</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">{{ $location->name }} · {{ $location->type }}</p>

    <div id="dup-box" class="mb-6 p-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-sm hidden">
        <p class="font-semibold text-amber-900 dark:text-amber-100 mb-2">Possible duplicates</p>
        <ul id="dup-list" class="list-disc pl-5 text-amber-900 dark:text-amber-100/90"></ul>
    </div>

    <form id="loc-form" class="space-y-4">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Name</label>
            <input type="text" name="name" value="{{ old('name', $location->name) }}" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm" />
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Type</label>
            <select name="type" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm">
                @foreach (['country','state','district','taluka','village'] as $t)
                    <option value="{{ $t }}" @selected($location->type === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Category</label>
            <select name="category" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm">
                <option value="">-- none --</option>
                @foreach (['metro','city','town','village','suburban'] as $c)
                    <option value="{{ $c }}" @selected(($location->category ?? '') === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Parent ID (nullable)</label>
            <input type="number" name="parent_id" value="{{ $location->parent_id }}" class="w-full rounded border-gray-300 dark:bg-gray-700 text-sm" />
        </div>
        <div class="flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1" id="is_active" @checked($location->is_active) />
            <label for="is_active" class="text-sm text-gray-700 dark:text-gray-300">Active</label>
        </div>
        <div class="flex gap-2 pt-2">
            <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium">Save</button>
            <a href="{{ route('admin.locations.index') }}" class="px-4 py-2 rounded-lg bg-gray-200 dark:bg-gray-600 text-sm">Back</a>
        </div>
    </form>
    <p id="form-msg" class="mt-4 text-sm"></p>
</div>

<script>
(function () {
    var id = {{ (int) $location->id }};
    var INTERNAL = '/admin/internal/locations/' + id;
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    fetch(INTERNAL + '/possible-duplicates', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success || !j.data || !j.data.length) return;
            document.getElementById('dup-box').classList.remove('hidden');
            document.getElementById('dup-list').innerHTML = j.data.map(function (d) {
                return '<li>#' + d.id + ' ' + d.name + ' (' + d.type + ') · score ' + d.score + '</li>';
            }).join('');
        });

    document.getElementById('loc-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(e.target);
        var body = {
            name: fd.get('name'),
            type: fd.get('type'),
            category: fd.get('category') === '' ? null : fd.get('category'),
            parent_id: fd.get('parent_id') === '' ? null : parseInt(fd.get('parent_id'), 10),
            is_active: document.getElementById('is_active').checked
        };
        document.getElementById('form-msg').textContent = 'Saving…';
        fetch(INTERNAL, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                document.getElementById('form-msg').textContent = x.ok && x.j.success ? 'Saved.' : (x.j.message || 'Error');
            });
    });
})();
</script>
@endsection
