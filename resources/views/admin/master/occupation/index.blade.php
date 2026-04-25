@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Occupation Master</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Manage occupation categories and workplace dependency mapping.</p>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    <div x-data="{ tab: 'occupations' }" class="space-y-4">
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-700 dark:bg-gray-800">
            <button
                type="button"
                @click="tab = 'occupations'"
                :class="tab === 'occupations' ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700'"
                class="rounded-md px-3 py-2 text-sm font-semibold transition"
            >
                Occupations
            </button>
            <button
                type="button"
                @click="tab = 'categories'"
                :class="tab === 'categories' ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700'"
                class="rounded-md px-3 py-2 text-sm font-semibold transition"
            >
                Occupation Categories
            </button>
        </div>

        <div x-show="tab === 'categories'" x-cloak class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Occupation Categories (Workplace)</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Set workplace dependency here (maps to working_with_types).</p>
                <form method="POST" action="{{ route('admin.master.occupation-categories.store') }}" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    @csrf
                    <input type="text" name="name" required placeholder="Category name" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <select name="legacy_working_with_type_id" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">No workplace mapping</option>
                        @foreach ($workingWithTypes as $wwt)
                            <option value="{{ $wwt->id }}">{{ $wwt->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="sort_order" min="0" value="0" placeholder="Sort" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <button type="submit" class="md:col-span-3 inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Add Occupation Category</button>
                </form>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Occupation category list</h3>
                    <form method="GET" action="{{ route('admin.master.occupation.index') }}" class="flex items-center gap-2">
                        <input type="hidden" name="sort_by" value="{{ $currentOccupationSort ?? (($hasOccupationSortOrder ?? false) ? 'sort_order' : 'name_asc') }}">
                        <label for="occupation_category_sort" class="text-xs font-medium text-gray-600 dark:text-gray-300">Sort by</label>
                        <select id="occupation_category_sort" name="category_sort" onchange="this.form.submit()" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option value="sort_order" {{ ($currentOccupationCategorySort ?? 'sort_order') === 'sort_order' ? 'selected' : '' }}>Sort order</option>
                            <option value="name_asc" {{ ($currentOccupationCategorySort ?? '') === 'name_asc' ? 'selected' : '' }}>Name A-Z</option>
                            <option value="name_desc" {{ ($currentOccupationCategorySort ?? '') === 'name_desc' ? 'selected' : '' }}>Name Z-A</option>
                            <option value="workplace" {{ ($currentOccupationCategorySort ?? '') === 'workplace' ? 'selected' : '' }}>Workplace</option>
                        </select>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50">
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Category</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Workplace mapping</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Sort</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($occupationCategories as $category)
                                <tr x-data="{ showEditModal: false }" class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $category->name }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $category->workingWithType?->name ?? 'No mapping' }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $category->sort_order }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="showEditModal = true" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Edit</button>
                                            <form method="POST" action="{{ route('admin.master.occupation-categories.destroy', $category) }}" onsubmit="return confirm('Delete this occupation category?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">Delete</button>
                                            </form>
                                        </div>

                                        <div
                                            x-show="showEditModal"
                                            x-cloak
                                            class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 px-4"
                                            @click.self="showEditModal = false"
                                            @keydown.escape.window="showEditModal = false"
                                        >
                                            <div class="w-full max-w-xl rounded-lg bg-white p-4 shadow-xl dark:bg-gray-800">
                                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Edit occupation category</h3>
                                                <form method="POST" action="{{ route('admin.master.occupation-categories.update', $category) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="text" name="name" value="{{ $category->name }}" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                                    <select name="legacy_working_with_type_id" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                                        <option value="">No workplace mapping</option>
                                                        @foreach ($workingWithTypes as $wwt)
                                                            <option value="{{ $wwt->id }}" {{ (int) $category->legacy_working_with_type_id === (int) $wwt->id ? 'selected' : '' }}>{{ $wwt->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input type="number" name="sort_order" min="0" value="{{ $category->sort_order }}" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                                    <div class="md:col-span-2 flex items-center justify-end gap-2">
                                                        <button type="button" @click="showEditModal = false" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                                        <button type="submit" class="rounded-md bg-indigo-700 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-600">Save</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-4 text-sm text-gray-500 dark:text-gray-300">No occupation categories found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div x-show="tab === 'occupations'" x-cloak class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Occupations</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">While editing occupation, select category to set workplace dependency.</p>
                <form method="POST" action="{{ route('admin.master.occupations.store') }}" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                    @csrf
                    <input type="text" name="name" required placeholder="Occupation name" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <select name="category_id" required class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">Select category/workplace</option>
                        @foreach ($occupationCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @if (($hasOccupationSortOrder ?? false))
                        <input type="number" name="sort_order" min="0" value="0" placeholder="Sort order" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    @endif
                    <button type="submit" class="md:col-span-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Add Occupation</button>
                </form>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Occupation list</h3>
                    <form method="GET" action="{{ route('admin.master.occupation.index') }}" class="flex items-center gap-2">
                        <input type="hidden" name="category_sort" value="{{ $currentOccupationCategorySort ?? 'sort_order' }}">
                        <label for="sort_by" class="text-xs font-medium text-gray-600 dark:text-gray-300">Sort by</label>
                        <select id="sort_by" name="sort_by" onchange="this.form.submit()" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            @if (($hasOccupationSortOrder ?? false))
                                <option value="sort_order" {{ ($currentOccupationSort ?? 'sort_order') === 'sort_order' ? 'selected' : '' }}>Sort order</option>
                            @endif
                            <option value="name_asc" {{ ($currentOccupationSort ?? '') === 'name_asc' ? 'selected' : '' }}>Name A-Z</option>
                            <option value="name_desc" {{ ($currentOccupationSort ?? '') === 'name_desc' ? 'selected' : '' }}>Name Z-A</option>
                            <option value="category" {{ ($currentOccupationSort ?? '') === 'category' ? 'selected' : '' }}>Category</option>
                            <option value="usage_desc" {{ ($currentOccupationSort ?? '') === 'usage_desc' ? 'selected' : '' }}>Most used</option>
                        </select>
                    </form>
                </div>

                <div class="mt-3 max-h-[40rem] overflow-y-auto overflow-x-auto pr-1">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50">
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Category</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Occupation</th>
                                @if (($hasOccupationSortOrder ?? false))
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Sort</th>
                                @endif
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Users</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                    @forelse ($occupations as $occupation)
                        @php
                            $replacementOptions = $occupationCategories
                                ->flatMap(fn ($c) => $c->occupations)
                                ->filter(fn ($o) => (int) $o->id !== (int) $occupation->id)
                                ->values();
                            $usedCount = (int) ($occupationUsageCounts[$occupation->id] ?? 0);
                        @endphp
                        <tr x-data="{ showDeleteModal: false, showEditModal: false }" class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200" title="{{ $occupation->category?->name }}">{{ $occupation->category?->name ?? '—' }}</td>
                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100" title="{{ $occupation->name }}">{{ $occupation->name }}</td>
                            @if (($hasOccupationSortOrder ?? false))
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ (int) ($occupation->sort_order ?? 0) }}</td>
                            @endif
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $usedCount }}</td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="showEditModal = true" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Edit</button>
                                    <button type="button" @click="showDeleteModal = true" class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">Delete</button>
                                </div>

                            <div
                                x-show="showEditModal"
                                x-cloak
                                class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 px-4"
                                @click.self="showEditModal = false"
                                @keydown.escape.window="showEditModal = false"
                            >
                                <div class="w-full max-w-xl rounded-lg bg-white p-4 shadow-xl dark:bg-gray-800">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Edit occupation</h3>
                                    <form method="POST" action="{{ route('admin.master.occupations.update', $occupation) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                        @csrf
                                        @method('PUT')
                                        <select name="category_id" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                            @foreach ($occupationCategories as $optCategory)
                                                <option value="{{ $optCategory->id }}" {{ (int) $occupation->category_id === (int) $optCategory->id ? 'selected' : '' }}>
                                                    {{ $optCategory->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="name" value="{{ $occupation->name }}" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                        @if (($hasOccupationSortOrder ?? false))
                                            <input type="number" name="sort_order" min="0" value="{{ (int) ($occupation->sort_order ?? 0) }}" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                        @endif
                                        <div class="md:col-span-2 flex items-center justify-end gap-2">
                                            <button type="button" @click="showEditModal = false" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                            <button type="submit" class="rounded-md bg-indigo-700 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-600">Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div
                                x-show="showDeleteModal"
                                x-cloak
                                class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 px-4"
                                @click.self="showDeleteModal = false"
                                @keydown.escape.window="showDeleteModal = false"
                            >
                                <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl dark:bg-gray-800">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Delete occupation</h3>
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                        Select replacement before delete. Affected records: <span class="font-semibold">{{ $usedCount }}</span>
                                    </p>
                                    <form method="POST" action="{{ route('admin.master.occupations.destroy', $occupation) }}" class="mt-3 space-y-3">
                                        @csrf
                                        @method('DELETE')
                                        <select name="replacement_occupation_id" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                            <option value="">Replacement occupation (required)</option>
                                            @foreach ($replacementOptions as $replacement)
                                                <option value="{{ $replacement->id }}">{{ $replacement->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" @click="showDeleteModal = false" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                            <button type="submit" @disabled($replacementOptions->isEmpty()) class="rounded-md bg-red-700 px-3 py-2 text-xs font-semibold text-white hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-50">Confirm Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ ($hasOccupationSortOrder ?? false) ? 5 : 4 }}" class="px-3 py-4 text-sm text-gray-500 dark:text-gray-300">No occupations found.</td>
                        </tr>
                    @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection

