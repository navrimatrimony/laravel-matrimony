@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6" x-data="{ adminEditMode: false }">
    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
    @endif
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-1">Admin — Profile #{{ $matrimonyProfile->id }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ $matrimonyProfile->full_name ?? '—' }}@if (!empty($matrimonyProfile->is_demo)) <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 rounded">Demo</span>@endif</p>
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Lifecycle: <strong>{{ $matrimonyProfile->lifecycle_state ?? 'Active' }}</strong></p>

    @if (!empty($lifecycleAllowedTargets ?? []))
    <div class="mb-4 p-4 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/30">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Change Lifecycle State (Day 7)</h3>
        <form method="POST" action="{{ route('admin.profiles.lifecycle-state', $matrimonyProfile) }}" class="flex items-end gap-2">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Target state</label>
                <select name="lifecycle_state" class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                    @foreach ($lifecycleAllowedTargets as $target)
                        @php
                            $tooltip = match($target) {
                                'Active' => 'Profile is fully active. Visible in search, accessible via direct link, and interest / shortlist are allowed.',
                                'Search-Hidden' => 'Profile is hidden from search results. Still accessible via direct link and interactions are allowed. Owner can edit the profile.',
                                'Owner-Hidden' => 'Profile is visible only to the owner. Hidden from search and direct access by others. Interest and shortlist are blocked. Owner can still edit the profile.',
                                'Draft' => 'Profile is incomplete. Not visible in search and interactions are disabled. Owner can edit the profile.',
                                'Suspended' => 'Profile is temporarily disabled by admin. Search, view, edit, and interactions are blocked.',
                                'Archived' => 'Profile is permanently inactive. Search, view, edit, and interactions are blocked.',
                                default => '',
                            };
                        @endphp
                        <option value="{{ $target }}" title="{{ $tooltip }}">{{ $target }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-sm font-medium">Apply</button>
        </form>
    </div>
    @endif

    <div class="mb-6">
        <button type="button" @click="adminEditMode = !adminEditMode" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium text-sm transition-colors">
            <span x-text="adminEditMode ? 'Cancel Edit' : 'Edit Profile'"></span>
        </button>
    </div>

    <div x-data="{ activeAction: null }" class="mb-6 p-6 rounded-lg border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Moderation</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Profile suspend, unsuspend, soft delete, image approve/reject, visibility override. All actions require a reason.</p>
            </div>
            <button 
                type="button"
                @click="$parent.adminEditMode = !$parent.adminEditMode"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium text-sm transition-colors">
                <span x-text="$parent.adminEditMode ? 'Cancel Edit' : 'Edit Profile (Admin)'"></span>
            </button>
        </div>
        <div class="flex flex-wrap gap-2 mb-4">
            <button 
                type="button"
                @click="activeAction = activeAction === 'suspend' ? null : 'suspend'"
                style="padding:8px 16px; background:#f59e0b; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Suspend
            </button>
            <button 
                type="button"
                @click="activeAction = activeAction === 'unsuspend' ? null : 'unsuspend'"
                style="padding:8px 16px; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Unsuspend
            </button>
            <button 
                type="button"
                @click="activeAction = activeAction === 'soft-delete' ? null : 'soft-delete'"
                style="padding:8px 16px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Soft Delete
            </button>
            @if ($matrimonyProfile->profile_photo)
            <button 
                type="button"
                @click="activeAction = activeAction === 'approve-image' ? null : 'approve-image'"
                style="padding:8px 16px; background:#3b82f6; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Approve Image
            </button>
            <button 
                type="button"
                @click="activeAction = activeAction === 'reject-image' ? null : 'reject-image'"
                style="padding:8px 16px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Reject Image
            </button>
            @endif
            <button 
                type="button"
                @click="activeAction = activeAction === 'override-visibility' ? null : 'override-visibility'"
                style="padding:8px 16px; background:#8b5cf6; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:500;">
                Override Visibility
            </button>
        </div>

        <div x-show="activeAction === 'suspend'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.suspend', $matrimonyProfile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Suspend Profile</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#f59e0b; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
        <div x-show="activeAction === 'unsuspend'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.unsuspend', $matrimonyProfile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Unsuspend Profile</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
        <div x-show="activeAction === 'soft-delete'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.soft-delete', $matrimonyProfile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Soft Delete Profile</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
        @if ($matrimonyProfile->profile_photo)
        <div x-show="activeAction === 'approve-image'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.approve-image', $matrimonyProfile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Approve Image</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#3b82f6; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
        <div x-show="activeAction === 'reject-image'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.reject-image', $matrimonyProfile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Reject Image</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
        @endif
        <div x-show="activeAction === 'override-visibility'" x-transition style="border:1px solid #ccc; padding:1rem; border-radius:4px; margin-bottom:1rem; background:#fff;">
            <form method="POST" action="{{ route('admin.profiles.override-visibility', $matrimonyProfile) }}">
                @csrf
                <p style="font-weight:600; margin-bottom:8px;">Override visibility (force search visible even if &lt;70% complete)</p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="Reason (minimum 10 characters)" style="width:100%; margin-bottom:8px; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                <div style="display:flex; gap:8px;">
                    <button type="submit" style="padding:8px 16px; background:#8b5cf6; color:white; border:none; border-radius:4px; cursor:pointer;">Submit</button>
                    <button type="button" @click="activeAction = null" style="padding:8px 16px; background:#6b7280; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-6">
        <div x-show="adminEditMode" x-transition class="mb-6 p-6 bg-yellow-50 dark:bg-yellow-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-lg">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4">Admin Profile Edit Mode</h3>
            <form method="POST" action="{{ route('admin.profiles.update', $matrimonyProfile) }}" id="admin-profile-edit-form">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Name</label>
                        <input type="text" name="full_name" value="{{ old('full_name', $matrimonyProfile->full_name) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth</label>
                        <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $matrimonyProfile->date_of_birth) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marital Status</label>
                        <select name="marital_status" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <option value="">—</option>
                            <option value="single" {{ old('marital_status', $matrimonyProfile->marital_status) === 'single' ? 'selected' : '' }}>Single</option>
                            <option value="divorced" {{ old('marital_status', $matrimonyProfile->marital_status) === 'divorced' ? 'selected' : '' }}>Divorced</option>
                            <option value="widowed" {{ old('marital_status', $matrimonyProfile->marital_status) === 'widowed' ? 'selected' : '' }}>Widowed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Education</label>
                        <input type="text" name="education" value="{{ old('education', $matrimonyProfile->education) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location</label>
                        <input type="text" name="location" value="{{ old('location', $matrimonyProfile->location) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Caste</label>
                        <input type="text" name="caste" value="{{ old('caste', $matrimonyProfile->caste) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Height (cm)</label>
                        <input type="number" name="height_cm" value="{{ old('height_cm', $matrimonyProfile->height_cm) }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" placeholder="—">
                    </div>
                </div>

                @php
                    $extendedFields = \App\Models\FieldRegistry::where('field_type', 'EXTENDED')
                        ->where('is_archived', false)
                        ->orderBy('category')
                        ->orderBy('display_order')
                        ->get();
                    $extendedValues = \App\Services\ExtendedFieldService::getValuesForProfile($matrimonyProfile);
                @endphp
                @if ($extendedFields->count() > 0)
                <div class="mb-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 uppercase tracking-wider">EXTENDED FIELDS</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach ($extendedFields as $field)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    {{ $field->display_label }}
                                </label>
                                @if ($field->data_type === 'text')
                                    <input type="text" 
                                           name="extended_fields[{{ $field->field_key }}]" 
                                           value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" 
                                           class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @elseif ($field->data_type === 'number')
                                    <input type="number" 
                                           name="extended_fields[{{ $field->field_key }}]" 
                                           value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" 
                                           class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @elseif ($field->data_type === 'date')
                                    <input type="date" 
                                           name="extended_fields[{{ $field->field_key }}]" 
                                           value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" 
                                           class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @elseif ($field->data_type === 'boolean')
                                    <select name="extended_fields[{{ $field->field_key }}]" 
                                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                        <option value="">—</option>
                                        <option value="1" {{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') == '1' ? 'selected' : '' }}>Yes</option>
                                        <option value="0" {{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') == '0' ? 'selected' : '' }}>No</option>
                                    </select>
                                @elseif ($field->data_type === 'select')
                                    <input type="text" 
                                           name="extended_fields[{{ $field->field_key }}]" 
                                           value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" 
                                           class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Edit Reason <span class="text-red-600">*</span>
                    </label>
                    <textarea name="edit_reason" rows="3" required minlength="10" placeholder="Explain why you are editing this profile (minimum 10 characters)" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">{{ old('edit_reason') }}</textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">This reason will be logged in the audit log.</p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium">Save Changes</button>
                    <button type="button" @click="$root.adminEditMode = false" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md font-medium">Cancel</button>
                </div>
            </form>
        </div>

        <div class="mb-6">
            <div class="flex justify-between items-center mb-1">
                <span class="text-sm font-medium text-gray-700">Profile Completeness</span>
                <span class="text-sm font-bold text-gray-900">{{ $completenessPct }}%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
                <div class="bg-indigo-600 h-2.5 rounded-full transition-all duration-300" style="width: {{ $completenessPct }}%;"></div>
            </div>
        </div>

        <div class="mb-6 flex flex-col items-center">
            @if ($matrimonyProfile->profile_photo && $matrimonyProfile->photo_approved !== false)
                <img
                    src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
                    alt="Profile Photo"
                    class="w-40 h-40 rounded-full object-cover border"
                />
            @else
                @php
                    $gender = $matrimonyProfile->gender ?? null;
                    if ($gender === 'male') {
                        $placeholderSrc = asset('images/placeholders/male-profile.svg');
                    } elseif ($gender === 'female') {
                        $placeholderSrc = asset('images/placeholders/female-profile.svg');
                    } else {
                        $placeholderSrc = asset('images/placeholders/default-profile.svg');
                    }
                @endphp
                <img
                    src="{{ $placeholderSrc }}"
                    alt="Profile Placeholder"
                    class="w-40 h-40 rounded-full object-cover border"
                />
                @if (!empty($matrimonyProfile->is_demo))
                    <span class="text-xs text-gray-500 mt-1">Demo profile</span>
                @endif
            @endif
        </div>

        <div class="text-center mb-6">
            <h2 class="text-2xl font-semibold">
                {{ $matrimonyProfile->full_name }}
                @if ($matrimonyProfile->admin_edited_fields && in_array('full_name', $matrimonyProfile->admin_edited_fields ?? []))
                    <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
                @endif
            </h2>
            <p class="text-gray-500">
                {{ ($matrimonyProfile->gender ?? $matrimonyProfile->user?->gender) ? ucfirst($matrimonyProfile->gender ?? $matrimonyProfile->user?->gender) : '—' }}
            </p>
        </div>

        @if ($matrimonyProfile->photo_rejection_reason)
            <div style="margin-bottom:1.5rem; padding:1rem; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b;">
                <p style="font-weight:600; margin-bottom:0.5rem;">Profile photo was removed by admin.</p>
                <p style="margin:0;"><strong>Reason:</strong> {{ $matrimonyProfile->photo_rejection_reason }}</p>
            </div>
        @endif

        <div class="mb-6">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 uppercase">Field Lock Status (Day-6, read-only)</h3>
            @php
                $coreFieldKeys = ['full_name', 'date_of_birth', 'marital_status', 'education', 'location', 'caste', 'height_cm'];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-xs">
                @foreach ($coreFieldKeys as $fk)
                    <div class="px-3 py-2 rounded bg-gray-50 dark:bg-gray-700/50">
                        <span class="font-medium">{{ $fk }}:</span>
                        @if (isset($fieldLocks[$fk]))
                            <span class="text-amber-600 dark:text-amber-400">Locked</span>
                            <span class="block text-gray-500">{{ $fieldLocks[$fk]['locked_by_name'] ?? '—' }}</span>
                            <span class="block text-gray-500">{{ $fieldLocks[$fk]['locked_at'] ?? '—' }}</span>
                        @else
                            <span class="text-gray-500">No</span>
                        @endif
                    </div>
                @endforeach
                @foreach ($fieldLocks ?? [] as $fk => $lock)
                    @if (!in_array($fk, $coreFieldKeys, true))
                        <div class="px-3 py-2 rounded bg-gray-50 dark:bg-gray-700/50">
                            <span class="font-medium">{{ $fk }} (EXT):</span>
                            <span class="text-amber-600 dark:text-amber-400">Locked</span>
                            <span class="block text-gray-500">{{ $lock['locked_by_name'] ?? '—' }}</span>
                            <span class="block text-gray-500">{{ $lock['locked_at'] ?? '—' }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-gray-500 text-sm">Date of Birth</p>
                <p class="font-medium text-base">
                    {{ $matrimonyProfile->date_of_birth ?? '—' }}
                    @if ($matrimonyProfile->admin_edited_fields && in_array('date_of_birth', $matrimonyProfile->admin_edited_fields ?? []))
                        <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Marital Status</p>
                <p class="font-medium text-base">
                    {{ ($matrimonyProfile->marital_status ?? '') ? ucfirst($matrimonyProfile->marital_status) : '—' }}
                    @if ($matrimonyProfile->admin_edited_fields && in_array('marital_status', $matrimonyProfile->admin_edited_fields ?? []))
                        <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Education</p>
                <p class="font-medium text-base">
                    {{ $matrimonyProfile->education ?? '—' }}
                    @if ($matrimonyProfile->admin_edited_fields && in_array('education', $matrimonyProfile->admin_edited_fields ?? []))
                        <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Location</p>
                <p class="font-medium text-base">
                    {{ $matrimonyProfile->location ?? '—' }}
                    @if ($matrimonyProfile->admin_edited_fields && in_array('location', $matrimonyProfile->admin_edited_fields ?? []))
                        <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Caste</p>
                <p class="font-medium text-base">
                    {{ $matrimonyProfile->caste ?? '—' }}
                    @if ($matrimonyProfile->admin_edited_fields && in_array('caste', $matrimonyProfile->admin_edited_fields ?? []))
                        <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-gray-500 text-sm">Height (cm)</p>
                <p class="font-medium text-base">{{ $matrimonyProfile->height_cm ?? '—' }}</p>
            </div>
        </div>

        @php
            $extendedFieldsDisplay = \App\Models\FieldRegistry::where('field_type', 'EXTENDED')
                ->where('is_archived', false)
                ->orderBy('category')
                ->orderBy('display_order')
                ->get();
            $extendedValuesDisplay = \App\Services\ExtendedFieldService::getValuesForProfile($matrimonyProfile);
        @endphp
        @if ($extendedFieldsDisplay->count() > 0)
        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-600">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4 uppercase tracking-wider">EXTENDED FIELDS</h3>
            <form method="POST" action="{{ route('admin.profiles.update', $matrimonyProfile) }}">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    @foreach ($extendedFieldsDisplay as $field)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $field->display_label }}</label>
                            @if ($field->data_type === 'text')
                                <input type="text" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValuesDisplay[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            @elseif ($field->data_type === 'number')
                                <input type="number" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValuesDisplay[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            @elseif ($field->data_type === 'date')
                                <input type="date" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValuesDisplay[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            @elseif ($field->data_type === 'boolean')
                                <select name="extended_fields[{{ $field->field_key }}]" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                    <option value="">—</option>
                                    <option value="1" {{ old("extended_fields.{$field->field_key}", $extendedValuesDisplay[$field->field_key] ?? '') == '1' ? 'selected' : '' }}>Yes</option>
                                    <option value="0" {{ old("extended_fields.{$field->field_key}", $extendedValuesDisplay[$field->field_key] ?? '') == '0' ? 'selected' : '' }}>No</option>
                                </select>
                            @else
                                <input type="text" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValuesDisplay[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Edit Reason <span class="text-red-600">*</span></label>
                    <textarea name="edit_reason" rows="2" required minlength="10" placeholder="Reason for editing (min 10 characters)" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">{{ old('edit_reason') }}</textarea>
                </div>
                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-medium text-sm">Save EXTENDED fields</button>
            </form>
        </div>
        @endif
    </div>
</div>
@endsection
