@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
#cropperWrap { position: relative; }
#cropperWrap .cropper-container { width: 320px !important; height: 320px !important; max-width: 320px !important; overflow: hidden !important; }
#cropperWrap .cropper-wrap-box,
#cropperWrap .cropper-drag-box { max-width: 100%; max-height: 100%; }
#cropperWrap .cropper-crop-box { max-width: 100%; max-height: 100%; border: 2px solid #22d3ee; }
#cropperWrap .cropper-view-box { outline: 2px solid #22d3ee; }
#cropperWrap .cropper-point { width: 14px; height: 14px; background: #22d3ee; opacity: 1; border-radius: 2px; pointer-events: auto; cursor: move; }
#cropperWrap .cropper-line { background: #22d3ee; opacity: 0.6; pointer-events: auto; }
#cropperWrap .cropper-face { cursor: move; }

/* Photo gallery: keep 3 cards on one row (desktop), degrade to 2/1 on small screens. */
#galleryOrder { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
@media (max-width: 860px) {
    #galleryOrder { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
}
@media (max-width: 520px) {
    #galleryOrder { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }
}

/* Mobile landscape: show upload panel + gallery side-by-side, same height. */
@media (orientation: landscape) and (max-width: 900px) {
    #uploadManagerPage { max-width: none !important; width: 70vw !important; padding: 0 12px; }
    #uploadManagerTwoCol { display: flex; gap: 16px; align-items: stretch; }
    .upload-main-col { flex: 1; min-width: 0; }
    .upload-gallery-col { flex: 1; min-width: 0; margin-top: 0 !important; }
    /* Avoid inner scrollbars; let the page scroll naturally. */
    .upload-main-col, .upload-gallery-col { max-height: none; overflow: visible; }
}

/* Fallback for browsers that do not reliably expose `orientation`. */
@media (max-width: 900px) and (min-aspect-ratio: 1/1) {
    #uploadManagerPage { max-width: none !important; width: 70vw !important; padding: 0 12px; }
    #uploadManagerTwoCol { display: flex; gap: 16px; align-items: stretch; }
    .upload-main-col { flex: 1; min-width: 0; }
    .upload-gallery-col { flex: 1; min-width: 0; margin-top: 0 !important; }
    /* Avoid inner scrollbars; let the page scroll naturally. */
    .upload-main-col, .upload-gallery-col { max-height: none; overflow: visible; }
}

/* Fallback: browsers that do not expose reliable orientation. */
body.upload-landscape #uploadManagerPage {
    max-width: none !important;
    width: 70vw !important;
    padding: 0 12px;
}

body.upload-landscape #uploadManagerTwoCol {
    display: flex;
    gap: 16px;
    align-items: stretch;
}

body.upload-landscape .upload-main-col,
body.upload-landscape .upload-gallery-col {
    flex: 1;
    min-width: 0;
}

body.upload-landscape .upload-gallery-col {
    margin-top: 0 !important;
}

body.upload-landscape .upload-main-col,
body.upload-landscape .upload-gallery-col {
    max-height: none;
    overflow: visible;
}
</style>

<div style="min-height: 80vh; background: linear-gradient(135deg, #fdf2f8 0%, #f5f3ff 50%, #eff6ff 100%); padding: 40px 16px;">
    @if (!empty($fromOnboarding))
        <div style="max-width: 520px; margin: 0 auto 16px auto; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <a href="{{ route('matrimony.onboarding.show', ['step' => 7]) }}"
               style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; color: #374151; font-weight: 700; font-size: 14px; text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                ← {{ __('onboarding.photo_flow_edit_step5') }}
            </a>
            <a href="{{ route('matrimony.onboarding.complete') }}"
               style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; background: linear-gradient(90deg, #4f46e5, #7c3aed); border: 1px solid #6366f1; border-radius: 12px; color: #ffffff; font-weight: 700; font-size: 14px; text-decoration: none; box-shadow: 0 2px 10px rgba(79, 70, 229, 0.35);">
                {{ __('onboarding.photo_flow_finish') }}
            </a>
        </div>
    @endif
    <div id="uploadManagerPage" style="max-width: 520px; margin: 0 auto;">
        @php
            $currentPhotoCount = isset($currentPhotoCount) ? (int) $currentPhotoCount : (isset($galleryPhotos) ? $galleryPhotos->count() : 0);
            $photoMaxPerProfile = isset($photoMaxPerProfile) ? (int) $photoMaxPerProfile : 0;
            $photoSlotsRemaining = isset($photoSlotsRemaining)
                ? (int) $photoSlotsRemaining
                : max(0, $photoMaxPerProfile - $currentPhotoCount);
            $photoLimitReached = isset($photoLimitReached) ? (bool) $photoLimitReached : ($currentPhotoCount >= $photoMaxPerProfile);
        @endphp

        {{-- Success Banner --}}
        @if (session('success'))
        <div style="background: linear-gradient(90deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; text-align: center;">
            <p style="margin: 0; color: #065f46; font-size: 16px; font-weight: 600;">
                {{ session('success') ?: __('photo.profile_live_add_photo_more_responses') }}
            </p>
        </div>
        @endif

        @if (session('error'))
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; text-align: left;">
                <p style="margin: 0; font-weight: 800; color: #991b1b; font-size: 14px;">{{ session('error') }}</p>
            </div>
        @endif

        @if (session('warning'))
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; text-align: left;">
                <p style="margin: 0; font-weight: 800; color: #92400e; font-size: 14px;">{{ session('warning') }}</p>
            </div>
        @endif

        {{-- Fetch error box (for AJAX/fetch uploads) --}}
        <div id="uploadFetchErrorBox" style="display:none; background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; text-align: left;">
            <p id="uploadFetchErrorText" style="margin: 0; font-weight: 800; color: #991b1b; font-size: 14px;"></p>
        </div>

        {{-- Validation Errors --}}
        @if ($errors->any())
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; text-align: left;">
                <p style="margin: 0 0 8px 0; font-weight: 700; color: #991b1b; font-size: 14px;">
                    Please fix the following issue{{ $errors->count() > 1 ? 's' : '' }}:
                </p>
                <ul style="margin: 0; padding-left: 18px;">
                    @foreach ($errors->all() as $error)
                        <li style="color: #7f1d1d; font-size: 13px; margin: 4px 0;">{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Progress Steps --}}
        <div style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-bottom: 32px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 14px;">✓</div>
                <span style="font-size: 14px; color: #059669; font-weight: 500;">{{ __('photo.profile_details') }}</span>
            </div>
            <div style="width: 40px; height: 2px; background: #d1d5db;"></div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; background: #4f46e5; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 14px;">2</div>
                <span style="font-size: 14px; color: #4f46e5; font-weight: 600;">{{ __('photo.upload_photo') }}</span>
            </div>
        </div>

        <div id="uploadManagerTwoCol">
            {{-- Main Card --}}
            <div class="upload-main-col" style="background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 32px 24px; text-align: center;">

            {{-- Photo rejection warning --}}
            @if (auth()->check() && auth()->user()->matrimonyProfile && auth()->user()->matrimonyProfile->photo_rejection_reason)
                <div style="margin-bottom: 24px; padding: 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; text-align: left;">
                    <p style="font-weight: 600; margin: 0 0 8px 0; color: #991b1b; font-size: 14px;">{{ __('photo.previous_photo_removed') }}</p>
                    <p style="margin: 0; color: #7f1d1d; font-size: 13px;"><strong>{{ __('common.reason') }}:</strong> {{ auth()->user()->matrimonyProfile->photo_rejection_reason }}</p>
                </div>
            @endif

            {{-- Photo limit reached banner --}}
            @if ($photoLimitReached)
                <div style="margin-bottom: 24px; padding: 16px 18px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 14px; text-align: left;">
                    <p style="margin: 0 0 6px 0; font-weight: 900; color: #92400e; font-size: 15px;">Photo limit reached</p>
                    <p style="margin: 0; color: #92400e; font-size: 13px; font-weight: 600;">
                        You have used all {{ $photoMaxPerProfile }} photo slots. Delete one photo before uploading a new one.
                    </p>
                </div>
            @endif

            {{-- Title --}}
            <h1 style="font-size: 24px; font-weight: 700; color: #1f2937; margin: 0 0 8px 0;">
                {{ __('photo.upload_your_photo') }}
            </h1>
            <p style="font-size: 14px; color: #6b7280; margin: 0 0 28px 0;">
                {{ __('photo.good_photo_better_matches') }}
            </p>

            @php
                $profile = auth()->user()->matrimonyProfile;
                $gender = $profile->gender ?? null;
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

                {{-- Premium summary strip --}}
                <div style="margin: 0 0 20px 0; padding: 14px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 14px; text-align: left;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
                        <div>
                            <div style="font-size: 12px; color: #6b7280; font-weight: 700;">Total photos used</div>
                            <div style="font-size: 16px; color: #111827; font-weight: 950;">{{ $currentPhotoCount }} / {{ $photoMaxPerProfile }} photos</div>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end;">
                            <div style="padding: 7px 10px; border-radius: 999px; background: #ffffff; border: 1px solid #e5e7eb; color: #374151; font-size: 12px; font-weight: 800;">
                                Remaining: {{ $photoSlotsRemaining }}
                            </div>
                            <div style="padding: 7px 10px; border-radius: 999px; background: #ffffff; border: 1px solid #e5e7eb; color: #374151; font-size: 12px; font-weight: 800;">
                                @if (! $photoApprovalRequired)
                                    Photos go live immediately
                                @else
                                    New photos stay pending until review
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Step 1: Photo Upload Area --}}
                <div id="stepChoose" style="display: block;">
                    <label for="profile_photo_input" style="display: block; cursor: {{ $photoLimitReached ? 'not-allowed' : 'pointer' }};">
                        <div style="width: 160px; height: 160px; margin: 0 auto 16px auto; border-radius: 50%; border: 3px solid {{ $photoLimitReached ? '#d1d5db' : $borderColor }}; overflow: hidden; position: relative; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,0,0,0.1); {{ $photoLimitReached ? 'filter: grayscale(0.4); opacity: 0.75;' : '' }}" id="uploadPlaceholder">
                            <img src="{{ $placeholderImage }}" alt="{{ __('photo.upload_photo') }}" style="width: 100%; height: 100%; object-fit: cover;" id="placeholderImg">
                            <img src="" alt="{{ __('photo.preview') }}" style="width: 100%; height: 100%; object-fit: cover; display: none; position: absolute; top: 0; left: 0;" id="previewImg">
                            <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.5); padding: 8px 0; display: flex; align-items: center; justify-content: center;" id="cameraOverlay">
                                <svg style="width: 20px; height: 20px; color: white;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </label>
                    <p style="font-size: 13px; color: {{ $photoLimitReached ? '#92400e' : '#6b7280' }}; font-weight: 700; margin: 0 0 8px 0;">
                        @if ($photoLimitReached)
                            Delete one photo to upload another
                        @else
                            {{ __('photo.click_to_upload') }}
                        @endif
                    </p>
                    <input type="file" name="profile_photo" id="profile_photo_input" {{ $photoLimitReached ? 'disabled' : 'required' }} multiple accept="image/jpeg,image/png" style="display: none;">
                    <p id="selectedFileName" style="font-size: 14px; color: #059669; font-weight: 500; margin: 0 0 8px 0; display: none;"></p>
                </div>

                {{-- Step 2: Preview + Crop/Rotate (optional) — shown after file select --}}
                <div id="stepPreview" style="display: none;">
                    <p style="font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 12px 0;">{{ __('photo.preview') }}</p>
                    <div style="width: 200px; height: 200px; margin: 0 auto 8px auto; border-radius: 50%; border: 3px solid #10b981; overflow: hidden; background: #f3f4f6;">
                        <img id="smallPreviewImg" src="" alt="{{ __('photo.preview') }}" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                    <p style="font-size: 13px; color: #6b7280; margin: 0 0 8px 0;">{!! __('photo.adjust_crop_help_html') !!}</p>
                    <div id="cropperWrap" style="width: 320px; height: 320px; margin: 0 auto 16px auto; background: #1f2937; border-radius: 12px; overflow: hidden;">
                        <div id="cropperContainer" style="width: 320px; height: 320px;">
                            <img id="cropperImage" src="" alt="Crop" style="display: block; max-width: none;">
                        </div>
                    </div>
                    <div style="display: flex; justify-content: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                        <button type="button" id="btnCrop" style="padding: 10px 18px; background: #10b981; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;">✂ {{ __('photo.crop') }}</button>
                        <button type="button" id="btnRotateLeft" style="padding: 10px 18px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 500; color: #374151; cursor: pointer;">↺ {{ __('photo.rotate_left') }}</button>
                        <button type="button" id="btnRotateRight" style="padding: 10px 18px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 500; color: #374151; cursor: pointer;">↻ {{ __('photo.rotate_right') }}</button>
                        <button type="button" id="btnChangePhoto" style="padding: 10px 18px; background: #fff; border: 1px solid #9ca3af; border-radius: 8px; font-size: 14px; color: #6b7280; cursor: pointer;">{{ __('photo.change_photo') }}</button>
                    </div>
                </div>

                <p style="font-size: 13px; color: #9ca3af; margin: 0 0 24px 0;">
                    {{ __('photo.clear_face_jpg_png_max_2mb') }}
                </p>

                {{-- Trust indicator --}}
                <div style="background: #fef3c7; border-radius: 8px; padding: 12px 16px; margin-bottom: 24px;">
                    <p style="margin: 0; font-size: 13px; color: #92400e;">
                        {!! __('photo.profiles_with_photos_get_more_interests_html') !!}
                    </p>
                </div>

                {{-- Primary CTA --}}
                <button
                    type="submit"
                    id="btnSubmit"
                    {{ $photoLimitReached ? 'disabled' : '' }}
                    style="width: 100%; background: {{ $photoLimitReached ? '#e5e7eb' : 'linear-gradient(90deg, #059669, #10b981)' }}; color: {{ $photoLimitReached ? '#6b7280' : 'white' }}; padding: 16px 24px; border-radius: 10px; font-weight: 800; font-size: 16px; border: none; cursor: {{ $photoLimitReached ? 'not-allowed' : 'pointer' }}; box-shadow: {{ $photoLimitReached ? 'none' : '0 4px 14px rgba(16, 185, 129, 0.4)' }}; transition: transform 0.2s, box-shadow 0.2s;"
                >
                    @if ($photoLimitReached)
                        Photo limit reached
                    @else
                        {{ __('photo.upload_photo_complete_profile') }} ✓
                    @endif
                </button>
            </form>

            {{-- Secondary action --}}
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #f3f4f6;">
                <a href="{{ route('matrimony.profile.show', auth()->user()->matrimonyProfile->id) }}" style="font-size: 13px; color: #9ca3af; text-decoration: none;">
                    {{ __('wizard.skip_for_now') }} →
                </a>
            </div>
        </div>

            {{-- Your uploaded photos --}}
            <div class="upload-gallery-col" style="margin-top: 36px; text-align: left; background: #ffffff; border-radius: 18px; box-shadow: 0 18px 50px rgba(17,24,39,0.06); padding: 22px 20px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap;">
                <div>
                    <div style="font-size: 18px; font-weight: 950; color: #111827; margin-bottom: 4px;">Your uploaded photos</div>
                    <div style="font-size: 13px; color: #6b7280;">
                        {{ $currentPhotoCount }} / {{ $photoMaxPerProfile }} photos used
                        @if ($photoLimitReached)
                            <span style="margin-left: 8px; font-weight: 900; color: #92400e;">(Limit reached)</span>
                        @endif
                    </div>
                    <div style="margin-top: 6px; font-size: 13px; color: #374151; font-weight: 700;">
                        Select one photo to show first on your profile.
                    </div>
                </div>
            </div>

            @if ($galleryPhotos->isEmpty())
                <div style="margin-top: 14px; font-size: 13px; color: #6b7280;">
                    Your gallery is empty. Upload a photo above to get started.
                </div>
            @else
                @if ($photoLimitReached)
                    <div style="margin-top: 14px; padding: 12px 14px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 14px; color: #92400e; font-weight: 900; font-size: 13px;">
                        Your gallery is full. Delete one photo to add another.
                    </div>
                @endif
                @php
                    $primaryPhoto = $galleryPhotos->firstWhere('is_primary', true);
                @endphp

                @if ($primaryPhoto)
                    {{-- Featured primary photo --}}
                    <div style="margin-top: 16px; padding: 14px 14px; background: linear-gradient(135deg, rgba(5,150,105,0.12) 0%, rgba(79,70,229,0.08) 100%); border: 1px solid rgba(5,150,105,0.20); border-radius: 16px;">
                        <div style="display:flex; gap: 14px; align-items:center; flex-wrap: wrap;">
                            <div style="width: 92px; height: 92px; border-radius: 14px; overflow:hidden; background:#f3f4f6; flex: none; border: 1px solid rgba(5,150,105,0.22);">
                                <img src="{{ asset('uploads/matrimony_photos/'.$primaryPhoto->file_path) }}" alt="Primary Photo profile photo" style="width:100%; height:100%; object-fit:cover;">
                            </div>
                            <div style="flex:1; min-width: 200px;">
                                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <span style="padding:6px 10px; border-radius:999px; background:#059669; color:#fff; font-size:12px; font-weight:900;">Primary Photo</span>
                                    @php
                                        $primaryStatus = (string) ($primaryPhoto->approved_status ?? '');
                                        $primaryStatusText = $primaryStatus === 'approved' ? 'approved' : ($primaryStatus === 'pending' ? 'pending' : 'rejected');
                                    @endphp
                                    <span style="
                                        padding:6px 10px;
                                        border-radius:999px;
                                        font-size:12px;
                                        font-weight:900;
                                        color: #111827;
                                        background: {{ $primaryStatus === 'approved' ? '#dcfce7' : ($primaryStatus === 'pending' ? '#fef3c7' : '#fee2e2') }};
                                        border: 1px solid {{ $primaryStatus === 'approved' ? '#86efac' : ($primaryStatus === 'pending' ? '#fbbf24' : '#fca5a5') }};
                                    ">
                                        {{ $primaryStatusText }}
                                    </span>
                                </div>
                                <div style="margin-top: 8px; font-size: 13px; color: #374151; font-weight: 700;">
                                    This photo appears first on your profile.
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <div id="galleryOrder" style="margin-top: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(185px, 1fr)); gap: 16px;">
                    @foreach ($galleryPhotos as $photo)
                        @php
                            $status = (string) ($photo->approved_status ?? '');
                            $statusLabel = $status === 'approved' ? 'approved' : ($status === 'pending' ? 'pending' : 'rejected');
                        @endphp

                        <div class="photo-card" data-photo-id="{{ $photo->id }}" style="border: 1px solid #e5e7eb; border-radius: 16px; padding: 12px; background: #ffffff; box-shadow: 0 10px 30px rgba(17,24,39,0.06); {{ $photo->is_primary ? 'border-color: rgba(5,150,105,0.35); box-shadow: 0 16px 40px rgba(5,150,105,0.12);' : '' }}">
                            <div style="position: relative; border-radius: 14px; overflow: hidden; width: 100%; aspect-ratio: 1 / 1; background: #f3f4f6;">
                                <img
                                    src="{{ asset('uploads/matrimony_photos/'.$photo->file_path) }}"
                                    alt="Profile photo"
                                    style="width: 100%; height: 100%; object-fit: cover;"
                                >

                                @if ($photo->is_primary)
                                    <div style="position: absolute; top: 10px; left: 10px; background: #059669; color: #fff; font-size: 12px; padding: 6px 10px; border-radius: 999px; font-weight: 950; box-shadow: 0 10px 26px rgba(5,150,105,0.25);">
                                        Primary
                                    </div>
                                @endif
                            </div>

                            <div style="margin-top: 10px; text-align: center;">
                                <div style="
                                    display: inline-block;
                                    padding: 6px 10px;
                                    border-radius: 999px;
                                    font-size: 12px;
                                    font-weight: 950;
                                    text-transform: capitalize;
                                    background: {{ $status === 'approved' ? '#dcfce7' : ($status === 'pending' ? '#fef3c7' : '#fee2e2') }};
                                    border: 1px solid {{ $status === 'approved' ? '#86efac' : ($status === 'pending' ? '#fbbf24' : '#fca5a5') }};
                                    color: #111827;
                                ">
                                    {{ $statusLabel }}
                                </div>

                                <div style="display: flex; gap: 6px; justify-content: center;">
                                    <button type="button" class="move-btn" data-dir="left" style="padding: 6px 10px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 12px; font-size: 12px; cursor: pointer; font-weight: 900;">←</button>
                                    <button type="button" class="move-btn" data-dir="right" style="padding: 6px 10px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 12px; font-size: 12px; cursor: pointer; font-weight: 900;">→</button>
                                </div>

                                @if ($photo->is_primary)
                                    <div style="margin-top: 10px; display:flex; align-items:center; justify-content:center; gap: 8px; padding: 8px 10px; border-radius: 12px; background: rgba(5,150,105,0.06); border: 1px solid rgba(5,150,105,0.12);">
                                        <input type="checkbox" checked disabled style="width: 16px; height: 16px; accent-color: #059669;">
                                        <div style="font-size: 12px; font-weight: 950; color: #059669;">Primary</div>
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('matrimony.profile.photos.make-primary', $photo->id) }}" style="margin-top: 10px;">
                                        @csrf
                                        <label for="primary_select_{{ $photo->id }}" style="display:flex; align-items:center; justify-content:center; gap: 8px; padding: 8px 10px; border-radius: 12px; background: transparent; border: none; box-shadow: none; outline: none; cursor: pointer;">
                                            <input
                                                id="primary_select_{{ $photo->id }}"
                                                type="checkbox"
                                                class="primary-select-checkbox"
                                                data-photo-id="{{ $photo->id }}"
                                                style="width: 16px; height: 16px; accent-color: #059669;"
                                            >
                                            <div style="font-size: 12px; font-weight: 950; color: #059669;">Make Primary</div>
                                        </label>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('matrimony.profile.photos.destroy', $photo->id) }}" style="margin-top: 8px;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="width: 100%; padding: 9px 10px; background: #ef4444; color: #fff; border: none; border-radius: 12px; font-weight: 950; font-size: 12px; cursor: pointer;"
                                        onclick="return confirm('Delete this photo?');">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! $galleryPhotos->isEmpty())
                <div style="margin-top: 18px;">
                    <form id="reorderForm" method="POST" action="{{ route('matrimony.profile.photos.reorder') }}">
                        @csrf
                        <div id="photoIdsInputs">
                            @foreach ($galleryPhotos as $photo)
                                <input type="hidden" name="photo_ids[]" value="{{ $photo->id }}">
                            @endforeach
                        </div>
                        <button type="submit" style="width: 100%; padding: 12px 16px; background: linear-gradient(90deg, #111827, #1f2937); color: #fff; border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; font-weight: 950; font-size: 14px; cursor: pointer; box-shadow: 0 14px 34px rgba(17,24,39,0.18);">
                            Save
                        </button>
                    </form>
                </div>
            @endif
            </div>
        </div>

        {{-- Footer note --}}
        <p style="text-align: center; font-size: 12px; color: #9ca3af; margin-top: 24px;">
            {{ __('photo.secure_visible_to_registered_members') }}
        </p>

    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function() {
    const form = document.getElementById('photoUploadForm');
    const fileInput = document.getElementById('profile_photo_input');
    const stepChoose = document.getElementById('stepChoose');
    const stepPreview = document.getElementById('stepPreview');
    const placeholderImg = document.getElementById('placeholderImg');
    const previewImg = document.getElementById('previewImg');
    const cameraOverlay = document.getElementById('cameraOverlay');
    const uploadPlaceholder = document.getElementById('uploadPlaceholder');
    const selectedFileName = document.getElementById('selectedFileName');
    const smallPreviewImg = document.getElementById('smallPreviewImg');
    const cropperImage = document.getElementById('cropperImage');
    const btnCrop = document.getElementById('btnCrop');
    const btnRotateLeft = document.getElementById('btnRotateLeft');
    const btnRotateRight = document.getElementById('btnRotateRight');
    const btnChangePhoto = document.getElementById('btnChangePhoto');
    const btnSubmit = document.getElementById('btnSubmit');

    let cropper = null;
    let currentDataUrl = null;

    const photoLimitReached = {{ $photoLimitReached ? 'true' : 'false' }};
    const photoSlotsRemaining = {{ (int) $photoSlotsRemaining }};
    const photoMaxPerProfile = {{ (int) $photoMaxPerProfile }};

    const uploadLimitMessage = 'You have already used all ' + photoMaxPerProfile + ' photo slots. Delete one photo before uploading a new one.';

    const uploadFetchErrorBox = document.getElementById('uploadFetchErrorBox');
    const uploadFetchErrorText = document.getElementById('uploadFetchErrorText');

    function showFetchError(message) {
        if (!uploadFetchErrorBox || !uploadFetchErrorText) return;
        uploadFetchErrorText.textContent = message || 'Upload failed. Please try again.';
        uploadFetchErrorBox.style.display = 'block';
    }

    function clearFetchError() {
        if (!uploadFetchErrorBox || !uploadFetchErrorText) return;
        uploadFetchErrorText.textContent = '';
        uploadFetchErrorBox.style.display = 'none';
    }

    function destroyCropper() {
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
    }

    function showPreview(dataUrl) {
        currentDataUrl = dataUrl;
        smallPreviewImg.src = dataUrl;
        previewImg.src = dataUrl;
        previewImg.style.display = 'block';
        placeholderImg.style.display = 'none';
        if (cameraOverlay) cameraOverlay.style.background = 'rgba(16, 185, 129, 0.7)';
        if (uploadPlaceholder) {
            uploadPlaceholder.style.borderColor = '#10b981';
            uploadPlaceholder.style.boxShadow = '0 4px 16px rgba(16, 185, 129, 0.3)';
        }
        stepChoose.style.display = 'none';
        stepPreview.style.display = 'block';
        destroyCropper();
        cropperImage.onload = null;
        cropperImage.src = dataUrl;
        function initCropper() {
            if (typeof Cropper === 'undefined') {
                console.error('Cropper.js did not load. Crop will not be available.');
                return;
            }
            cropperImage.onload = null;
            destroyCropper();
            cropper = new Cropper(cropperImage, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.8,
                restore: false,
                guides: true,
                center: true,
                highlight: true,
                cropBoxMovable: true,
                cropBoxResizable: true,
                crop: function() { updateSmallPreview(); },
            });
            updateSmallPreview();
            if (btnCrop) btnCrop.onclick = onCropClick;
            if (btnRotateLeft) btnRotateLeft.onclick = function() { if (cropper) { cropper.rotate(-90); updateSmallPreview(); } };
            if (btnRotateRight) btnRotateRight.onclick = function() { if (cropper) { cropper.rotate(90); updateSmallPreview(); } };
        }
        if (cropperImage.complete) setTimeout(initCropper, 10);
        else cropperImage.onload = initCropper;
    }

    function updateSmallPreview() {
        if (!cropper) return;
        const canvas = cropper.getCroppedCanvas({ width: 400, height: 400, imageSmoothingEnabled: true });
        if (canvas) smallPreviewImg.src = canvas.toDataURL('image/jpeg', 0.92);
    }

    /** Apply crop: replace image with cropped result so it's fixed and used on submit */
    function applyCrop() {
        if (!cropper) return;
        const canvas = cropper.getCroppedCanvas({ width: 800, height: 800, imageSmoothingEnabled: true });
        if (!canvas) return;
        const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
        currentDataUrl = dataUrl;
        updateSmallPreview();
        smallPreviewImg.src = dataUrl;
        destroyCropper();
        cropperImage.onload = null;
        cropperImage.src = dataUrl;
        function reinitCropper() {
            if (typeof Cropper === 'undefined') return;
            cropperImage.onload = null;
            destroyCropper();
            cropper = new Cropper(cropperImage, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                restore: false,
                guides: true,
                center: true,
                highlight: true,
                cropBoxMovable: true,
                cropBoxResizable: true,
                crop: function() { updateSmallPreview(); },
            });
            updateSmallPreview();
            if (btnCrop) btnCrop.onclick = onCropClick;
            if (btnRotateLeft) btnRotateLeft.onclick = function() { if (cropper) { cropper.rotate(-90); updateSmallPreview(); } };
            if (btnRotateRight) btnRotateRight.onclick = function() { if (cropper) { cropper.rotate(90); updateSmallPreview(); } };
        }
        if (cropperImage.complete) setTimeout(reinitCropper, 10);
        else cropperImage.onload = reinitCropper;
    }

    function onCropClick() {
        if (cropper) applyCrop();
    }

    btnChangePhoto.onclick = function() {
        destroyCropper();
        fileInput.value = '';
        currentDataUrl = null;
        stepPreview.style.display = 'none';
        stepChoose.style.display = 'block';
        previewImg.src = '';
        previewImg.style.display = 'none';
        placeholderImg.style.display = 'block';
        if (cameraOverlay) cameraOverlay.style.background = 'rgba(0,0,0,0.5)';
        if (uploadPlaceholder) {
            uploadPlaceholder.style.borderColor = '{{ $borderColor }}';
            uploadPlaceholder.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
        }
        selectedFileName.style.display = 'none';
    };

    fileInput.addEventListener('change', function() {
        clearFetchError();
        const selectedFiles = this.files ? Array.from(this.files) : [];
        const selectedCount = selectedFiles.length;

        if (selectedCount === 0) return;

        // Client-side hard-stop: prevent selecting more than remaining slots.
        if (photoLimitReached || selectedCount > photoSlotsRemaining) {
            const msg = photoSlotsRemaining === 0
                ? 'Your gallery is full. Delete one photo to upload another.'
                : 'You can add only ' + photoSlotsRemaining + ' more photo(s). Delete a photo first if you want to upload more.';

            showFetchError(msg);

            // Reset UI back to selection.
            this.value = '';
            destroyCropper();
            currentDataUrl = null;
            stepPreview.style.display = 'none';
            stepChoose.style.display = 'block';
            previewImg.src = '';
            previewImg.style.display = 'none';
            placeholderImg.style.display = 'block';
            if (uploadPlaceholder) {
                uploadPlaceholder.style.borderColor = '{{ $borderColor }}';
                uploadPlaceholder.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            }
            selectedFileName.style.display = 'none';
            return;
        }

        const file = selectedFiles[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            showPreview(e.target.result);
            selectedFileName.textContent = '✓ ' + file.name;
            selectedFileName.style.display = 'block';
        };
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', function(e) {
        const files = fileInput.files ? Array.from(fileInput.files) : [];
        const file = files[0];

        if (photoLimitReached || photoSlotsRemaining === 0) {
            // Hard stop UX: gallery is already full.
            e.preventDefault();
            showFetchError(uploadLimitMessage);
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Photo limit reached';
            return;
        }

        if (!file && !cropper) {
            // Prevent native browser validation. The file input is hidden (display:none),
            // so native validation shows "not focusable" and blocks properly saving.
            e.preventDefault();
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Upload Photo & Complete Profile ✓';
            showFetchError('Please select a photo first.');
            return;
        }
        e.preventDefault();
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Uploading…';

        function doSubmit(blob) {
            const fd = new FormData();
            fd.append('_token', document.querySelector('input[name="_token"]').value);
            fd.append('profile_photo', blob, (file && file.name) ? file.name.replace(/\.[^.]+$/, '.jpg') : 'photo.jpg');
            // Send remaining photos to gallery (no cropper for them; they are compressed server-side).
            if (files && files.length > 1) {
                for (let i = 1; i < files.length; i++) {
                    if (files[i]) {
                        fd.append('profile_photos[]', files[i], files[i].name);
                    }
                }
            }
            fetch(form.action, {
                method: 'POST',
                body: fd,
                redirect: 'follow',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                }
            })
                .then(async function(res) {
                    if (res.redirected) {
                        window.location.href = res.url || form.action;
                        return;
                    }
                    if (res.ok) {
                        window.location.reload();
                        return;
                    }

                    // Non-2xx: try to show server message (JSON 422), otherwise fallback to text.
                    let data = null;
                    try {
                        data = await res.json();
                    } catch (e) {
                        data = null;
                    }

                    if (data && typeof data.message === 'string' && data.message) {
                        showFetchError(data.message);
                    } else if (data && data.errors && data.errors.profile_photos && Array.isArray(data.errors.profile_photos) && data.errors.profile_photos[0]) {
                        showFetchError(data.errors.profile_photos[0]);
                    } else {
                        let fallbackText = '';
                        try {
                            fallbackText = await res.text();
                        } catch (e) {
                            fallbackText = '';
                        }
                        showFetchError(fallbackText ? fallbackText : 'Upload failed. Please try again.');
                    }

                    btnSubmit.disabled = false;
                    btnSubmit.textContent = 'Upload Photo & Complete Profile ✓';
                })
                .catch(function(err) {
                    console.error('Photo upload failed', err);
                    showFetchError('Upload failed. Please try again.');
                    btnSubmit.disabled = false;
                    btnSubmit.textContent = 'Upload Photo & Complete Profile ✓';
                });
        }

        if (cropper) {
            cropper.getCroppedCanvas({ width: 800, height: 800, imageSmoothingEnabled: true })
                .toBlob(function(blob) {
                    if (blob) doSubmit(blob);
                    else doSubmit(file);
                }, 'image/jpeg', 0.92);
        } else if (file) {
            doSubmit(file);
        } else {
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Upload Photo & Complete Profile ✓';
        }
    });
})();
</script>

<script>
(function() {
    const galleryOrder = document.getElementById('galleryOrder');
    const photoIdsInputs = document.getElementById('photoIdsInputs');
    if (!galleryOrder || !photoIdsInputs) return;

    function refreshHiddenPhotoIds() {
        const cards = Array.from(galleryOrder.querySelectorAll('.photo-card'));
        const html = cards.map(c => {
            const id = c.getAttribute('data-photo-id');
            return '<input type="hidden" name="photo_ids[]" value="' + id + '">';
        }).join('');
        photoIdsInputs.innerHTML = html;
    }

    galleryOrder.addEventListener('click', function(e) {
        const btn = e.target.closest('.move-btn');
        if (!btn) return;
        const dir = btn.getAttribute('data-dir');
        const card = e.target.closest('.photo-card');
        if (!card) return;

        const cards = Array.from(galleryOrder.querySelectorAll('.photo-card'));
        const idx = cards.indexOf(card);
        if (idx < 0) return;

        if (dir === 'left' && idx > 0) {
            const prev = cards[idx - 1];
            galleryOrder.insertBefore(card, prev);
            refreshHiddenPhotoIds();
        }

        if (dir === 'right' && idx < cards.length - 1) {
            const next = cards[idx + 1];
            galleryOrder.insertBefore(card, next.nextSibling);
            refreshHiddenPhotoIds();
        }
    });

    refreshHiddenPhotoIds();
})();
</script>

<script>
(function() {
    const checkboxes = Array.from(document.querySelectorAll('.primary-select-checkbox'));
    if (!checkboxes.length) return;

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function(e) {
            if (!e.target || !e.target.checked) return;
            const form = e.target.closest('form');
            if (form) form.submit();
        });
    });
})();
</script>

<script>
(function() {
    function updateLandscapeClass() {
        const isLandscape = window.innerWidth > window.innerHeight;
        document.body.classList.toggle('upload-landscape', isLandscape);
    }

    window.addEventListener('resize', updateLandscapeClass);
    document.addEventListener('DOMContentLoaded', updateLandscapeClass);
    updateLandscapeClass();
})();
</script>
@endsection
