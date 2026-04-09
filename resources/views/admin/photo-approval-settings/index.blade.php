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
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Photo verification & engine</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Two stages: (1) <strong>clean / automated-safe</strong> photos vs (2) <strong>flagged</strong> photos. They are controlled separately — turning off manual review for clean photos does <strong>not</strong> auto-approve nudity.</p>
    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 text-sm mb-4">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('admin.photo-approval-settings.update') }}" class="space-y-6">
        @csrf

        {{-- Stage 1: clean / safe path --}}
        <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg text-emerald-900 dark:text-emerald-100 text-sm">
            <p class="font-semibold mb-1">Stage 1 — Clean photos (NudeNet says “safe”)</p>
            <p class="mb-2">When <strong>OFF</strong> (default): photos that pass automated screening can be visible to others <strong>without</strong> admin (unless you also require primary visibility elsewhere).</p>
            <p>When <strong>ON</strong>: even <em>clean</em> photos stay hidden until an admin approves them on the profile / queue.</p>
            <p class="mt-2 text-xs opacity-90">This toggle does <strong>not</strong> apply to suspicious or flagged images — they always go to Stage 2.</p>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600">
            <label class="admin-toggle" id="photoApprovalToggle">
                <input type="checkbox" name="photo_approval_required" value="1" {{ $photoApprovalRequired ? 'checked' : '' }} onchange="updatePhotoApprovalUI()">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label {{ $photoApprovalRequired ? 'on' : 'off' }}" id="photoApprovalLabel">
                    {{ $photoApprovalRequired ? 'ON — Require admin for clean photos (hidden until approved)' : 'OFF — Clean photos can go live without admin (after automated screening)' }}
                </span>
            </label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Stored as <code class="text-xs">photo_approval_required</code>. Same setting as before — labels now match the two-stage model.</p>

            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">
                <label class="inline-flex items-start gap-3 text-sm text-gray-800 dark:text-gray-100 cursor-pointer">
                    <input type="checkbox" name="photo_verify_safe_with_secondary_ai" value="1" {{ ! empty($photoVerifySafeWithSecondaryAi) ? 'checked' : '' }} class="mt-1 rounded border-gray-300 dark:border-gray-600">
                    <span>
                        <span class="font-semibold block">Secondary AI check when NudeNet says “safe”</span>
                        <span class="text-xs text-gray-600 dark:text-gray-400">
                            Use when your local NudeNet service wrongly returns <code class="text-xs">safe:true</code> for explicit images. Runs the same OpenAI / Sarvam image moderation used in “Auto” mode, <strong>even if</strong> NudeNet passes. Requires a configured API key; if the API is unavailable, the photo is sent to <strong>manual review</strong> instead of auto-approving.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        {{-- Stage 2: flagged --}}
        <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg text-amber-950 dark:text-amber-100 text-sm mb-4">
            <p class="font-semibold mb-1">Stage 2 — Flagged / suspicious photos (NudeNet not safe)</p>
            <p>These <strong>never</strong> become “approved” just because Stage 1 is OFF. They stay pending until the pipeline below runs (manual queue or AI).</p>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-4">
            <div>
                <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Moderation pipeline (for flagged)</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    NudeNet runs first on each upload. If the image is suspicious, the system follows the mode below.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Moderation mode</label>
                    <select name="photo_moderation_mode" class="w-full max-w-xs rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-2">
                        <option value="manual" {{ ($photoModerationMode ?? 'manual') === 'manual' ? 'selected' : '' }}>Manual (suspicious → pending review)</option>
                        <option value="auto" {{ ($photoModerationMode ?? 'manual') === 'auto' ? 'selected' : '' }}>Auto (suspicious → AI moderation)</option>
                    </select>
                </div>

                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">AI provider</label>
                    <select name="photo_ai_provider" class="w-full max-w-xs rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-2">
                        <option value="openai" {{ ($photoAiProvider ?? 'openai') === 'openai' ? 'selected' : '' }}>OpenAI</option>
                        <option value="sarvam" {{ ($photoAiProvider ?? 'openai') === 'sarvam' ? 'selected' : '' }}>Sarvam</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Used when mode is Auto <strong>or</strong> when “Secondary AI check when NudeNet says safe” is enabled above.</p>
                </div>
            </div>
        </div>

        {{-- Primary photo & slots --}}
        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">Slots & primary photo</p>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="photo_primary_required" value="1" {{ $photoPrimaryRequired ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600">
                <span>Primary photo required for profile to be considered complete</span>
            </label>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Max photos per profile</label>
                    <input type="number" name="photo_max_per_profile" min="1" max="10" value="{{ $photoMaxPerProfile }}" class="w-24 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Recommended: 5</p>
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Max upload size (MB)</label>
                    <input type="number" name="photo_max_upload_mb" min="1" max="20" value="{{ $photoMaxUploadMb }}" class="w-24 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Large originals are resized anyway.</p>
                </div>
                <div>
                    <label class="block font-medium text-gray-700 dark:text-gray-200 mb-1">Max longest edge (px)</label>
                    <input type="number" name="photo_max_edge_px" min="400" max="2400" value="{{ $photoMaxEdgePx }}" class="w-28 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Default: 1200px</p>
                </div>
            </div>
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
        label.textContent = 'ON — Require admin for clean photos (hidden until approved)';
        label.classList.remove('off');
        label.classList.add('on');
    } else {
        label.textContent = 'OFF — Clean photos can go live without admin (after automated screening)';
        label.classList.remove('on');
        label.classList.add('off');
    }
}
</script>
@endsection
