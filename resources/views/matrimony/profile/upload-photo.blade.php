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
</style>

<div style="min-height: 80vh; background: linear-gradient(135deg, #fdf2f8 0%, #f5f3ff 50%, #eff6ff 100%); padding: 40px 16px;">
    <div style="max-width: 520px; margin: 0 auto;">

        {{-- Success Banner --}}
        @if (session('success'))
        <div style="background: linear-gradient(90deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; text-align: center;">
            <p style="margin: 0; color: #065f46; font-size: 16px; font-weight: 600;">
                {{ __('photo.profile_live_add_photo_more_responses') }}
            </p>
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

        {{-- Main Card --}}
        <div style="background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 32px 24px; text-align: center;">

            {{-- Photo rejection warning --}}
            @if (auth()->check() && auth()->user()->matrimonyProfile && auth()->user()->matrimonyProfile->photo_rejection_reason)
                <div style="margin-bottom: 24px; padding: 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; text-align: left;">
                    <p style="font-weight: 600; margin: 0 0 8px 0; color: #991b1b; font-size: 14px;">{{ __('photo.previous_photo_removed') }}</p>
                    <p style="margin: 0; color: #7f1d1d; font-size: 13px;"><strong>{{ __('common.reason') }}:</strong> {{ auth()->user()->matrimonyProfile->photo_rejection_reason }}</p>
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

                {{-- Step 1: Photo Upload Area --}}
                <div id="stepChoose" style="display: block;">
                    <label for="profile_photo_input" style="display: block; cursor: pointer;">
                        <div style="width: 160px; height: 160px; margin: 0 auto 16px auto; border-radius: 50%; border: 3px solid {{ $borderColor }}; overflow: hidden; position: relative; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,0,0,0.1);" id="uploadPlaceholder">
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
                    <p style="font-size: 13px; color: #6b7280; font-weight: 500; margin: 0 0 8px 0;">{{ __('photo.click_to_upload') }}</p>
                    <input type="file" name="profile_photo" id="profile_photo_input" required accept="image/jpeg,image/png" style="display: none;">
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
                <button type="submit" id="btnSubmit" style="width: 100%; background: linear-gradient(90deg, #059669, #10b981); color: white; padding: 16px 24px; border-radius: 10px; font-weight: 700; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4); transition: transform 0.2s, box-shadow 0.2s;">
                    {{ __('photo.upload_photo_complete_profile') }} ✓
                </button>
            </form>

            {{-- Secondary action --}}
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #f3f4f6;">
                <a href="{{ route('matrimony.profile.show', auth()->user()->matrimonyProfile->id) }}" style="font-size: 13px; color: #9ca3af; text-decoration: none;">
                    {{ __('wizard.skip_for_now') }} →
                </a>
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
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            showPreview(e.target.result);
            selectedFileName.textContent = '✓ ' + file.name;
            selectedFileName.style.display = 'block';
        };
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', function(e) {
        const file = fileInput.files && fileInput.files[0];
        if (!file && !cropper) {
            return;
        }
        e.preventDefault();
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Uploading…';

        function doSubmit(blob) {
            const fd = new FormData();
            fd.append('_token', document.querySelector('input[name="_token"]').value);
            fd.append('profile_photo', blob, (file && file.name) ? file.name.replace(/\.[^.]+$/, '.jpg') : 'photo.jpg');
            fetch(form.action, { method: 'POST', body: fd, redirect: 'follow' })
                .then(function(res) {
                    if (res.redirected) {
                        window.location.href = res.url;
                    } else if (res.ok) {
                        window.location.reload();
                    } else {
                        return res.text().then(function() {
                            throw new Error('Upload failed. Please try again.');
                        });
                    }
                })
                .catch(function(err) {
                    console.error('Photo upload failed', err);
                    btnSubmit.disabled = false;
                    btnSubmit.textContent = 'Upload Photo & Complete Profile ✓';
                    alert('Upload failed. Please try again.');
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
@endsection
