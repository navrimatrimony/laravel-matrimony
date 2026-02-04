@extends('layouts.app')

@section('content')
<div style="min-height: 80vh; background: linear-gradient(135deg, #fdf2f8 0%, #f5f3ff 50%, #eff6ff 100%); padding: 40px 16px;">
    <div style="max-width: 480px; margin: 0 auto;">

        {{-- Success Banner --}}
        @if (session('success'))
        <div style="background: linear-gradient(90deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; text-align: center;">
            <p style="margin: 0; color: #065f46; font-size: 16px; font-weight: 600;">
                🎉 Your profile is live! Add a photo to get 3× more responses.
            </p>
        </div>
        @endif

        {{-- Progress Steps --}}
        <div style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-bottom: 32px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 14px;">✓</div>
                <span style="font-size: 14px; color: #059669; font-weight: 500;">Profile Details</span>
            </div>
            <div style="width: 40px; height: 2px; background: #d1d5db;"></div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; background: #4f46e5; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 14px;">2</div>
                <span style="font-size: 14px; color: #4f46e5; font-weight: 600;">Upload Photo</span>
            </div>
        </div>

        {{-- Main Card --}}
        <div style="background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 32px 24px; text-align: center;">

            {{-- Photo rejection warning --}}
            @if (auth()->check() && auth()->user()->matrimonyProfile && auth()->user()->matrimonyProfile->photo_rejection_reason)
                <div style="margin-bottom: 24px; padding: 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; text-align: left;">
                    <p style="font-weight: 600; margin: 0 0 8px 0; color: #991b1b; font-size: 14px;">⚠️ Your previous photo was removed</p>
                    <p style="margin: 0; color: #7f1d1d; font-size: 13px;"><strong>Reason:</strong> {{ auth()->user()->matrimonyProfile->photo_rejection_reason }}</p>
                </div>
            @endif

            {{-- Title --}}
            <h1 style="font-size: 24px; font-weight: 700; color: #1f2937; margin: 0 0 8px 0;">
                Upload Your Photo
            </h1>
            <p style="font-size: 14px; color: #6b7280; margin: 0 0 28px 0;">
                A good photo helps you find better matches
            </p>

            @php
                $profile = auth()->user()->matrimonyProfile ?? null;
                $gender = $profile ? ($profile->gender ?? null) : null;
                if ($gender === 'male') {
                    $placeholderImage = asset('images/placeholders/male-profile.svg');
                    $borderColor = '#0ea5e9';
                } elseif ($gender === 'female') {
                    $placeholderImage = asset('images/placeholders/female-profile.svg');
                    $borderColor = '#ec4899';
                } else {
                    $placeholderImage = asset('images/placeholders/default-profile.svg');
                    $borderColor = '#9ca3af';
                }
            @endphp

            <form method="POST" action="{{ route('matrimony.profile.store-photo') }}" enctype="multipart/form-data" id="photoUploadForm">
                @csrf

                {{-- Photo Upload Area with Gender-based Placeholder --}}
                <label for="profile_photo_input" style="display: block; cursor: pointer;">
                    <div style="width: 160px; height: 160px; margin: 0 auto 16px auto; border-radius: 50%; border: 3px solid {{ $borderColor }}; overflow: hidden; position: relative; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,0,0,0.1);" id="uploadPlaceholder">
                        {{-- Gender-based placeholder image --}}
                        <img src="{{ $placeholderImage }}" alt="Upload photo" style="width: 100%; height: 100%; object-fit: cover;" id="placeholderImg">
                        {{-- Preview image (hidden initially) --}}
                        <img src="" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; display: none; position: absolute; top: 0; left: 0;" id="previewImg">
                        {{-- Camera overlay --}}
                        <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.5); padding: 8px 0; display: flex; align-items: center; justify-content: center;" id="cameraOverlay">
                            <svg style="width: 20px; height: 20px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                    </div>
                </label>

                <p style="font-size: 13px; color: #6b7280; font-weight: 500; margin: 0 0 8px 0;">Click to upload your photo</p>

                <input type="file" name="profile_photo" id="profile_photo_input" required accept="image/jpeg,image/png" style="display: none;" onchange="handleFileSelect(this)">

                <p id="selectedFileName" style="font-size: 14px; color: #059669; font-weight: 500; margin: 0 0 8px 0; display: none;"></p>

                <p style="font-size: 13px; color: #9ca3af; margin: 0 0 24px 0;">
                    Clear face photo • JPG or PNG • Max 2MB
                </p>

                {{-- Trust indicator --}}
                <div style="background: #fef3c7; border-radius: 8px; padding: 12px 16px; margin-bottom: 24px;">
                    <p style="margin: 0; font-size: 13px; color: #92400e;">
                        📸 <strong>Profiles with photos get 3× more interests</strong>
                    </p>
                </div>

                {{-- Primary CTA --}}
                <button type="submit" style="width: 100%; background: linear-gradient(90deg, #059669, #10b981); color: white; padding: 16px 24px; border-radius: 10px; font-weight: 700; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4); transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(16, 185, 129, 0.5)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 14px rgba(16, 185, 129, 0.4)';">
                    Upload Photo & Complete Profile ✓
                </button>
            </form>

            {{-- Secondary action --}}
            @if (auth()->user()->matrimonyProfile)
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #f3f4f6;">
                <a href="{{ route('matrimony.profile.show', auth()->user()->matrimonyProfile->id) }}" style="font-size: 13px; color: #9ca3af; text-decoration: none;">
                    Skip for now →
                </a>
            </div>
            @endif
        </div>

        {{-- Footer note --}}
        <p style="text-align: center; font-size: 12px; color: #9ca3af; margin-top: 24px;">
            🔒 Your photo is secure and only visible to registered members
        </p>

    </div>
</div>

<script>
function handleFileSelect(input) {
    const placeholder = document.getElementById('uploadPlaceholder');
    const placeholderImg = document.getElementById('placeholderImg');
    const previewImg = document.getElementById('previewImg');
    const cameraOverlay = document.getElementById('cameraOverlay');
    const fileNameEl = document.getElementById('selectedFileName');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();

        reader.onload = function(e) {
            // Show preview image
            previewImg.src = e.target.result;
            previewImg.style.display = 'block';
            placeholderImg.style.display = 'none';

            // Update border color to green (success)
            placeholder.style.borderColor = '#10b981';
            placeholder.style.boxShadow = '0 4px 16px rgba(16, 185, 129, 0.3)';

            // Hide camera overlay on preview
            cameraOverlay.style.background = 'rgba(16, 185, 129, 0.7)';
        };

        reader.readAsDataURL(file);

        // Show filename
        fileNameEl.textContent = '✓ ' + file.name;
        fileNameEl.style.display = 'block';
    }
}
</script>
@endsection
