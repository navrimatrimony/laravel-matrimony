@php
    $wizardPhotoQuery = [];
    if (auth()->check() && auth()->user()->isAnyAdmin() && ($profile->is_demo ?? false)) {
        $wizardPhotoQuery['profile_id'] = $profile->id;
    }
@endphp
{{-- Centralized photo workflow: always forward to the dedicated photo manager page. --}}
<div style="padding: 18px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;">
    <div style="font-weight: 900; color: #111827; margin-bottom: 6px;">
        Photo Manager
    </div>
    <div style="font-size: 13px; color: #6b7280;">
        Redirecting to your centralized photo manager so you can upload, delete, and select the primary photo.
    </div>
    <div style="margin-top: 12px;">
        <a href="{{ route('matrimony.profile.upload-photo', $wizardPhotoQuery) }}"
           style="display: inline-block; padding: 10px 14px; background: #4f46e5; color: #fff; border-radius: 10px; font-weight: 800; text-decoration: none;">
            Go to Photo Manager →
        </a>
    </div>
</div>
<script>
    // Immediate redirect keeps the wizard “photo” section consistent and avoids duplicated UI.
    window.location.replace(@json(route('matrimony.profile.upload-photo', $wizardPhotoQuery)));
</script>
