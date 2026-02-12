@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">Serious Intents</h1>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-8 p-4 border rounded-lg bg-gray-50 dark:bg-gray-700/40">
        <h2 class="text-lg font-semibold mb-3 text-gray-800 dark:text-gray-100">Create New Serious Intent</h2>
        <form method="POST" action="{{ route('admin.serious-intents.store') }}" class="flex gap-3 items-end">
            @csrf
            <div class="flex-1">
                <label class="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">Name</label>
                <input
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    maxlength="255"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                >
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md">Create</button>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full border border-gray-200 dark:border-gray-700">
            <thead class="bg-gray-100 dark:bg-gray-700">
                <tr>
                    <th class="text-left px-4 py-2 border-b">Intent</th>
                    <th class="text-left px-4 py-2 border-b">Status</th>
                    <th class="text-left px-4 py-2 border-b">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($intents as $intent)
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <td class="px-4 py-3">
                            @if ($intent->trashed())
                                <span class="text-gray-500 line-through">{{ $intent->name }}</span>
                            @else
                                <form method="POST" action="{{ route('admin.serious-intents.update', $intent->id) }}" class="flex gap-2 items-center">
                                    @csrf
                                    @method('PUT')
                                    <input
                                        type="text"
                                        name="name"
                                        value="{{ old('name', $intent->name) }}"
                                        required
                                        maxlength="255"
                                        class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-2 py-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                                    >
                                    <button type="submit" class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded">Save</button>
                                </form>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($intent->trashed())
                                <span class="inline-block px-2 py-1 text-xs rounded bg-red-100 text-red-700">Deleted</span>
                            @else
                                <span class="inline-block px-2 py-1 text-xs rounded bg-green-100 text-green-700">Active</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($intent->trashed())
                                <a href="{{ route('admin.serious-intents.restore-confirm', $intent->id) }}"
                                   class="inline-block px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded">
                                    Restore
                                </a>
                            @else
                                <form method="POST" action="{{ route('admin.serious-intents.destroy', $intent->id) }}" class="flex gap-2 items-center">
                                    @csrf
                                    @method('DELETE')
                                    <input
                                        type="text"
                                        name="reason"
                                        placeholder="Reason"
                                        required
                                        class="border border-gray-300 dark:border-gray-600 rounded-md px-2 py-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                                    >
                                    <button type="submit" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded">Soft Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-4 text-gray-500">No serious intents found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
