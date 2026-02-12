@extends('layouts.admin')

@section('content')
<div class="bg-white shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Admin Capabilities</h1>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Admin Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Manage Verification Tags</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Manage Serious Intents</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @foreach ($admins as $admin)
                    @php
                        $formId = 'cap-form-'.$admin->id;
                        $cap = $capabilities[$admin->id] ?? null;
                        $isSuperAdminRow = $admin->admin_role === 'super_admin';
                    @endphp
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $admin->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $admin->email }}</td>
                        <td class="px-4 py-3">
                            @if (!$isSuperAdminRow)
                                <input type="hidden" form="{{ $formId }}" name="can_manage_verification_tags" value="0">
                            @endif
                            <input
                                form="{{ $formId }}"
                                type="checkbox"
                                name="can_manage_verification_tags"
                                value="1"
                                {{ $isSuperAdminRow ? 'disabled' : '' }}
                                {{ !empty($cap?->can_manage_verification_tags) ? 'checked' : '' }}
                            >
                        </td>
                        <td class="px-4 py-3">
                            @if (!$isSuperAdminRow)
                                <input type="hidden" form="{{ $formId }}" name="can_manage_serious_intents" value="0">
                            @endif
                            <input
                                form="{{ $formId }}"
                                type="checkbox"
                                name="can_manage_serious_intents"
                                value="1"
                                {{ $isSuperAdminRow ? 'disabled' : '' }}
                                {{ !empty($cap?->can_manage_serious_intents) ? 'checked' : '' }}
                            >
                        </td>
                        <td class="px-4 py-3">
                            <form id="{{ $formId }}" method="POST" action="{{ route('admin.admin-capabilities.update', $admin->id) }}">
                                @csrf
                            </form>
                            @if (!$isSuperAdminRow)
                                <button type="submit" form="{{ $formId }}" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-sm">
                                    Save
                                </button>
                            @else
                                <span class="text-gray-400 text-sm">Super Admin (Locked)</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

