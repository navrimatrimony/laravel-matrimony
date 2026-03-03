@extends('layouts.admin')

@section('content')
{{-- Toggle Switch Styles --}}
<style>
.admin-toggle { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.admin-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.admin-toggle .toggle-track { width: 52px; height: 28px; background-color: #d1d5db; border-radius: 9999px; transition: background-color 0.2s ease; position: relative; }
.admin-toggle input:checked + .toggle-track { background-color: #10b981; }
.admin-toggle .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 24px; height: 24px; background-color: white; border-radius: 9999px; transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.admin-toggle input:checked + .toggle-track .toggle-thumb { transform: translateX(24px); }
.admin-toggle .toggle-label { margin-left: 12px; font-weight: 600; font-size: 14px; }
.admin-toggle .toggle-label.on { color: #059669; }
.admin-toggle .toggle-label.off { color: #6b7280; }
</style>

<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Photo verification</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Control whether profile photos need admin approval before they are visible to others. Default: approval not required (photos visible immediately).</p>
    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 text-sm mb-4">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-1">Behaviour</p>
        <p><strong>Approval not required (default):</strong> User uploads photo → photo is visible to everyone immediately. Admin can still approve/reject from profile for moderation.</p>
        <p class="mt-2"><strong>Approval required:</strong> User uploads photo → photo is hidden from others until admin approves. User sees "Under review". Admin approves or rejects from profile.</p>
    </div>
    <form method="POST" action="{{ route('admin.photo-approval-settings.update') }}" class="space-y-6">
        @csrf

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
            <label class="admin-toggle" id="photoApprovalToggle">
                <input type="checkbox" name="photo_approval_required" value="1" {{ $photoApprovalRequired ? 'checked' : '' }} onchange="updatePhotoApprovalUI()">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label {{ $photoApprovalRequired ? 'on' : 'off' }}" id="photoApprovalLabel">
                    {{ $photoApprovalRequired ? 'Photo approval REQUIRED (hidden until approved)' : 'Approval NOT required (photos visible immediately)' }}
                </span>
            </label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">When OFF (default), new uploads are visible immediately. When ON, new uploads stay hidden until admin approves from the profile page.</p>
        </div>

        <div class="pt-2">
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                Save settings
            </button>
        </div>
    </form>
</div>

<script>
function updatePhotoApprovalUI() {
    const checkbox = document.querySelector('#photoApprovalToggle input');
    const label = document.getElementById('photoApprovalLabel');
    if (checkbox.checked) {
        label.textContent = 'Photo approval REQUIRED (hidden until approved)';
        label.classList.remove('off');
        label.classList.add('on');
    } else {
        label.textContent = 'Approval NOT required (photos visible immediately)';
        label.classList.remove('on');
        label.classList.add('off');
    }
}
</script>
@endsection
