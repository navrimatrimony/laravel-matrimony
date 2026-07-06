<div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3">
    <a
        href="{{ route('admin.biodata-intakes.index') }}"
        class="rounded-lg px-4 py-2 text-sm font-semibold {{ ($activeAdminProfileTab ?? 'intake') === 'intake' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}"
    >
        Biodata Intake
    </a>
    <a
        href="{{ route('admin.biodata-intakes.create-profile') }}"
        class="rounded-lg px-4 py-2 text-sm font-semibold {{ ($activeAdminProfileTab ?? '') === 'manual' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}"
    >
        Manual Form
    </a>
    <a
        href="{{ route('admin.bulk-intakes.index') }}"
        class="rounded-lg px-4 py-2 text-sm font-semibold {{ ($activeAdminProfileTab ?? '') === 'bulk' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}"
    >
        Bulk Intake
    </a>
</div>
