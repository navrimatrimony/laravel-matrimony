@extends('layouts.admin')

@section('content')
@php
    $navSectionRows = collect($navSections ?? []);
    $sectionColumns = $navSectionRows->pluck('column')->values()->all();
    $allSectionsPreset = array_fill_keys($sectionColumns, true);
    $commandCenterOnlyPreset = array_fill_keys($sectionColumns, false);
    $commandCenterOnlyPreset['can_access_command_center'] = true;
    $presetFromSectionKeys = static fn (array $keys): array => $navSectionRows
        ->mapWithKeys(fn (array $section, string $sectionKey): array => [
            $section['column'] => in_array($sectionKey, $keys, true),
        ])
        ->all();
    $dataAdminPreset = $presetFromSectionKeys([
        \App\Support\Admin\AdminNavigationAccess::COMMAND_CENTER,
        \App\Support\Admin\AdminNavigationAccess::MEMBERS,
        \App\Support\Admin\AdminNavigationAccess::INTAKE_OCR,
        \App\Support\Admin\AdminNavigationAccess::TRUST_SAFETY,
        \App\Support\Admin\AdminNavigationAccess::MATCHING_DISCOVERY,
        \App\Support\Admin\AdminNavigationAccess::DATA_GOVERNANCE,
        \App\Support\Admin\AdminNavigationAccess::MASTER_DATA,
    ]);
    $auditorPreset = $presetFromSectionKeys([
        \App\Support\Admin\AdminNavigationAccess::COMMAND_CENTER,
        \App\Support\Admin\AdminNavigationAccess::TRUST_SAFETY,
        \App\Support\Admin\AdminNavigationAccess::DATA_GOVERNANCE,
    ]);
@endphp
<div class="space-y-6">
    <div class="bg-white shadow rounded-lg p-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Admin Capabilities</h1>
                <p class="mt-1 text-sm text-gray-600">Super admin sees everything. Other admins only see the sections selected here.</p>
            </div>
            <span class="inline-flex w-fit rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">Hidden navigation rule</span>
        </div>
    </div>

    @foreach ($admins as $admin)
        @php
            $cap = $capabilities[$admin->id] ?? null;
            $isSuperAdminRow = $admin->admin_role === 'super_admin';
            $sectionState = $navSectionRows
                ->mapWithKeys(fn (array $section): array => [
                    $section['column'] => $isSuperAdminRow || ! empty($cap?->{$section['column']}),
                ])
                ->all();
            $roleDefaultAccess = \App\Support\Admin\AdminNavigationAccess::defaultAccessFor($admin);
            $roleDefaultPreset = $navSectionRows
                ->mapWithKeys(fn (array $section, string $sectionKey): array => [
                    $section['column'] => (bool) ($roleDefaultAccess[$sectionKey] ?? false),
                ])
                ->all();
            $editorConfig = [
                'sections' => $sectionState,
                'presets' => [
                    'roleDefault' => $roleDefaultPreset,
                    'dataAdmin' => $dataAdminPreset,
                    'auditor' => $auditorPreset,
                    'commandCenterOnly' => $commandCenterOnlyPreset,
                    'all' => $allSectionsPreset,
                ],
            ];
        @endphp
        <form
            method="POST"
            action="{{ route('admin.admin-capabilities.update', $admin->id) }}"
            class="bg-white shadow rounded-lg p-6"
            data-admin-capability-editor="{{ $isSuperAdminRow ? 'locked' : 'editable' }}"
            @if (! $isSuperAdminRow) x-data="adminCapabilityEditor(@js($editorConfig))" @endif
        >
            @csrf

            <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-gray-900">{{ $admin->name }}</h2>
                        @if ($admin->admin_role)
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ $admin->admin_role }}</span>
                        @endif
                        @if ($isSuperAdminRow)
                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">All sections</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-gray-600">{{ $admin->email }}</p>
                    @if (! $isSuperAdminRow)
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-700" data-admin-section-count>
                                <span x-text="sectionCount()">{{ count(array_filter($sectionState)) }}</span>
                                <span>&nbsp;/ {{ count($sectionState) }} sections visible</span>
                            </span>
                            <span x-cloak x-show="sectionCount() === 0" class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 font-semibold text-amber-800">
                                No admin section selected
                            </span>
                        </div>
                    @endif
                </div>

                @if (!$isSuperAdminRow)
                    <button type="submit" class="inline-flex w-fit items-center justify-center rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Save access
                    </button>
                @else
                    <span class="text-sm font-medium text-gray-500">Super admin locked</span>
                @endif
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,18rem)_1fr]">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Fine-grained rights</h3>
                    <div class="mt-3 space-y-3">
                        @if (!$isSuperAdminRow)
                            <input type="hidden" name="can_manage_verification_tags" value="0">
                        @endif
                        <label class="flex items-start gap-3 rounded border border-gray-200 p-3 text-sm">
                            <input
                                type="checkbox"
                                name="can_manage_verification_tags"
                                value="1"
                                class="mt-1"
                                {{ $isSuperAdminRow ? 'disabled' : '' }}
                                {{ ($isSuperAdminRow || !empty($cap?->can_manage_verification_tags)) ? 'checked' : '' }}
                            >
                            <span>
                                <span class="block font-medium text-gray-900">Manage Verification Tags</span>
                                <span class="block text-xs text-gray-500">Create and edit profile verification tags.</span>
                            </span>
                        </label>

                        @if (!$isSuperAdminRow)
                            <input type="hidden" name="can_manage_serious_intents" value="0">
                        @endif
                        <label class="flex items-start gap-3 rounded border border-gray-200 p-3 text-sm">
                            <input
                                type="checkbox"
                                name="can_manage_serious_intents"
                                value="1"
                                class="mt-1"
                                {{ $isSuperAdminRow ? 'disabled' : '' }}
                                {{ ($isSuperAdminRow || !empty($cap?->can_manage_serious_intents)) ? 'checked' : '' }}
                            >
                            <span>
                                <span class="block font-medium text-gray-900">Manage Serious Intents</span>
                                <span class="block text-xs text-gray-500">Create and edit serious-intent labels.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div>
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Visible admin sections</h3>
                            <p class="mt-1 text-xs leading-5 text-gray-500">Hidden rule: admins only see selected sections. Super admin remains all-access.</p>
                        </div>
                        @if (! $isSuperAdminRow)
                            <div class="flex flex-wrap gap-2" data-admin-section-presets>
                                <button type="button" class="rounded border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50" data-admin-section-preset="role-default" @click="applyPreset('roleDefault')">Role default</button>
                                <button type="button" class="rounded border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50" data-admin-section-preset="data-admin" @click="applyPreset('dataAdmin')">Data admin</button>
                                <button type="button" class="rounded border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50" data-admin-section-preset="auditor" @click="applyPreset('auditor')">Auditor</button>
                                <button type="button" class="rounded border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50" data-admin-section-preset="command-center-only" @click="applyPreset('commandCenterOnly')">Command Center only</button>
                                <button type="button" class="rounded border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100" data-admin-section-preset="all" @click="selectAllSections()">All sections</button>
                            </div>
                        @endif
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach (($navSections ?? []) as $section)
                            @php
                                $column = $section['column'];
                                $checked = $isSuperAdminRow || !empty($cap?->{$column});
                            @endphp
                            @if (!$isSuperAdminRow)
                                <input type="hidden" name="{{ $column }}" value="0">
                            @endif
                            <label
                                class="flex min-h-24 items-start gap-3 rounded border border-gray-200 p-3 text-sm {{ $isSuperAdminRow && $checked ? 'bg-indigo-50/50 border-indigo-200' : '' }}"
                                @if (! $isSuperAdminRow)
                                    :class="sections['{{ $column }}'] ? 'bg-indigo-50/50 border-indigo-200' : ''"
                                @endif
                            >
                                <input
                                    type="checkbox"
                                    name="{{ $column }}"
                                    value="1"
                                    class="mt-1"
                                    @if (! $isSuperAdminRow) x-model="sections['{{ $column }}']" @endif
                                    {{ $isSuperAdminRow ? 'disabled' : '' }}
                                    {{ $checked ? 'checked' : '' }}
                                >
                                <span>
                                    <span class="block font-medium text-gray-900">{{ $section['label'] }}</span>
                                    <span class="mt-1 block text-xs leading-5 text-gray-500">{{ $section['description'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @if (! $isSuperAdminRow)
                        <div x-cloak x-show="sectionCount() === 0" class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">
                            This admin will not have any visible admin section after saving. Select at least one section unless this is intentional.
                        </div>
                    @endif
                </div>
            </div>
        </form>
    @endforeach
</div>
@endsection
