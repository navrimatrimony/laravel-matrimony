@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Education Master</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Manage education categories and degrees.</p>
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

    <div x-data="{ tab: 'degrees' }" class="space-y-4">
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-700 dark:bg-gray-800">
            <button
                type="button"
                @click="tab = 'degrees'"
                :class="tab === 'degrees' ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700'"
                class="rounded-md px-3 py-2 text-sm font-semibold transition"
            >
                Education Degrees
            </button>
            <button
                type="button"
                @click="tab = 'categories'"
                :class="tab === 'categories' ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700'"
                class="rounded-md px-3 py-2 text-sm font-semibold transition"
            >
                Education Categories
            </button>
        </div>

        <div x-show="tab === 'categories'" x-cloak class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Education Categories</h2>
                <form method="POST" action="{{ route('admin.master.education-categories.store') }}" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    @csrf
                    <input type="text" name="name" required placeholder="Category name" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <input type="number" name="sort_order" min="0" value="0" placeholder="Sort" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded border-gray-300 dark:border-gray-600">
                        Active
                    </label>
                    <button type="submit" class="md:col-span-3 inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Add Category</button>
                </form>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Education category list</h3>
                    <form method="GET" action="{{ route('admin.master.education.index') }}" class="flex items-center gap-2">
                        <input type="hidden" name="sort_by" value="{{ $currentDegreeSort ?? 'sort_order' }}">
                        <label for="education_category_sort" class="text-xs font-medium text-gray-600 dark:text-gray-300">Sort by</label>
                        <select id="education_category_sort" name="category_sort" onchange="this.form.submit()" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option value="sort_order" {{ ($currentEducationCategorySort ?? 'sort_order') === 'sort_order' ? 'selected' : '' }}>Sort order</option>
                            <option value="name_asc" {{ ($currentEducationCategorySort ?? '') === 'name_asc' ? 'selected' : '' }}>Name A-Z</option>
                            <option value="name_desc" {{ ($currentEducationCategorySort ?? '') === 'name_desc' ? 'selected' : '' }}>Name Z-A</option>
                            <option value="active_first" {{ ($currentEducationCategorySort ?? '') === 'active_first' ? 'selected' : '' }}>Active first</option>
                        </select>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50">
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Name</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Sort</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Status</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($educationCategories as $category)
                                <tr x-data="{ showEditModal: false }" class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $category->name }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $category->sort_order }}</td>
                                    <td class="px-3 py-2">
                                        <span class="{{ $category->is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ $category->is_active ? 'Active' : 'Disabled' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="showEditModal = true" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Edit</button>
                                            <form method="POST" action="{{ route('admin.master.education-categories.destroy', $category) }}" onsubmit="return confirm('Delete this education category?');">
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
                                            <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-xl dark:bg-gray-800">
                                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Edit education category</h3>
                                                <form method="POST" action="{{ route('admin.master.education-categories.update', $category) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="text" name="name" value="{{ $category->name }}" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                                    <input type="number" name="sort_order" min="0" value="{{ $category->sort_order }}" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                                    <label class="md:col-span-2 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                        <input type="checkbox" name="is_active" value="1" {{ $category->is_active ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600">
                                                        Active
                                                    </label>
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
                                    <td colspan="4" class="px-3 py-4 text-sm text-gray-500 dark:text-gray-300">No education categories found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div x-show="tab === 'degrees'" x-cloak class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Education Degrees</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><strong>Code</strong> हाच primary field आहे. <strong>Title</strong> आता auto-fill (Code = Title) होत असल्याने UI मधून काढला आहे.</p>
                <form method="POST" action="{{ route('admin.master.education-degrees.store') }}" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                    @csrf
                    <select name="category_id" required class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">Select category</option>
                        @foreach ($educationCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="code" required placeholder="Code (e.g. B.Tech)" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <input type="text" name="full_form" placeholder="Full form (optional)" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <input type="number" name="sort_order" min="0" value="0" placeholder="Sort" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Add Degree</button>
                </form>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Education degree list</h3>
                    <form method="GET" action="{{ route('admin.master.education.index') }}" class="flex items-center gap-2">
                        <input type="hidden" name="category_sort" value="{{ $currentEducationCategorySort ?? 'sort_order' }}">
                        <label for="sort_by" class="text-xs font-medium text-gray-600 dark:text-gray-300">Sort by</label>
                        <select id="sort_by" name="sort_by" onchange="this.form.submit()" class="rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option value="sort_order" {{ ($currentDegreeSort ?? 'sort_order') === 'sort_order' ? 'selected' : '' }}>Sort order</option>
                            <option value="code_asc" {{ ($currentDegreeSort ?? '') === 'code_asc' ? 'selected' : '' }}>Code A-Z</option>
                            <option value="code_desc" {{ ($currentDegreeSort ?? '') === 'code_desc' ? 'selected' : '' }}>Code Z-A</option>
                            <option value="category" {{ ($currentDegreeSort ?? '') === 'category' ? 'selected' : '' }}>Category</option>
                        </select>
                    </form>
                </div>

                <div class="mt-3 max-h-[40rem] overflow-y-auto overflow-x-auto pr-1">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700/50">
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Category</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Code</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Full form</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Sort</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Users</th>
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                    @forelse ($educationDegrees as $degree)
                        @php
                            $replacementOptions = $educationCategories
                                ->flatMap(fn ($c) => $c->degrees)
                                ->filter(fn ($d) => (int) $d->id !== (int) $degree->id)
                                ->values();
                            $usedCount = (int) ($educationUsageCounts[$degree->id] ?? 0);
                        @endphp
                        <tr x-data="{ showDeleteModal: false, showEditModal: false }" class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200" title="{{ $degree->category?->name }}">{{ $degree->category?->name ?? '—' }}</td>
                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100" title="{{ $degree->code }}">{{ $degree->code }}</td>
                            <td class="max-w-[16rem] truncate px-3 py-2 text-gray-700 dark:text-gray-300" title="{{ $degree->full_form }}">{{ filled($degree->full_form) ? $degree->full_form : '—' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $degree->sort_order }}</td>
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
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Edit education degree</h3>
                                    <form method="POST" action="{{ route('admin.master.education-degrees.update', $degree) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                        @csrf
                                        @method('PUT')
                                        <select name="category_id" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                            @foreach ($educationCategories as $optCat)
                                                <option value="{{ $optCat->id }}" {{ (int) $optCat->id === (int) $degree->category_id ? 'selected' : '' }}>{{ $optCat->name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="code" value="{{ $degree->code }}" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                        <input type="hidden" name="title" value="">
                                        <input type="text" name="full_form" value="{{ $degree->full_form }}" placeholder="Full form (optional)" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                        <input type="number" name="sort_order" value="{{ $degree->sort_order }}" min="0" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
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
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Delete education degree</h3>
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                        Select replacement before delete. Affected records: <span class="font-semibold">{{ $usedCount }}</span>
                                    </p>
                                    <form method="POST" action="{{ route('admin.master.education-degrees.destroy', $degree) }}" class="mt-3 space-y-3">
                                        @csrf
                                        @method('DELETE')
                                        <select name="replacement_degree_id" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                            <option value="">Replacement degree (required)</option>
                                            @foreach ($replacementOptions as $replacement)
                                                <option value="{{ $replacement->id }}">{{ $replacement->code }}{{ filled($replacement->full_form) ? ' - '.$replacement->full_form : '' }}</option>
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
                            <td colspan="6" class="px-3 py-4 text-sm text-gray-500 dark:text-gray-300">No education degrees found.</td>
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

