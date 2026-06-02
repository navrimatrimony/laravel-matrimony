document.addEventListener('DOMContentLoaded', () => {
    const img = document.getElementById('intake-manual-crop-img');
    const stage = document.getElementById('intake-manual-crop-stage');
    const box = document.getElementById('intake-photo-candidate-box');
    const saveBtn = document.getElementById('intake-photo-candidate-save');
    const clearBtn = document.getElementById('intake-photo-candidate-clear');
    const autoBtn = document.getElementById('intake-photo-candidate-auto');
    const ocrModeBtn = document.getElementById('intake-photo-candidate-mode-ocr');
    const messageEl = document.getElementById('intake-photo-candidate-message');
    const polygon = document.getElementById('intake-manual-crop-polygon');

    if (!img || !(img instanceof HTMLImageElement) || !stage || !box || !saveBtn) {
        return;
    }

    const PROFILE_CROP_EXPORT_W = 600;
    const PROFILE_CROP_EXPORT_H = 800;
    const PROFILE_CROP_ASPECT = PROFILE_CROP_EXPORT_W / PROFILE_CROP_EXPORT_H;

    const toastErr = (msg) => {
        const text = String(msg || '').trim();
        if (!text) return;
        if (window.toastr && typeof window.toastr.error === 'function') {
            window.toastr.error(text);
        } else {
            window.alert(text);
        }
    };

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const saveUrl = saveBtn.dataset.saveUrl;
    if (!saveUrl) return;

    let suggestion = null;
    const suggestionEl = document.getElementById('intake-photo-candidate-suggestion');
    if (suggestionEl?.textContent) {
        try {
            suggestion = JSON.parse(suggestionEl.textContent.trim());
        } catch {
            suggestion = null;
        }
    }

    let dragMode = null;
    let startClientX = 0;
    let startClientY = 0;
    let startBox = null;

    function setMessage(message) {
        if (messageEl && message) {
            messageEl.textContent = message;
        }
    }

    function stageRect() {
        return stage.getBoundingClientRect();
    }

    function currentBoxPct() {
        return {
            left: parseFloat(box.style.left || '20'),
            top: parseFloat(box.style.top || '8'),
            width: parseFloat(box.style.width || '30'),
            height: parseFloat(box.style.height || '40'),
        };
    }

    function enforceAspect(next) {
        const rect = stageRect();
        if (!rect.width || !rect.height) return next;

        let width = Number(next.width);
        let height = Number(next.height);
        if (!Number.isFinite(width) || !Number.isFinite(height)) return next;

        const targetHeight = (width / PROFILE_CROP_ASPECT) * (rect.width / rect.height);
        height = targetHeight;

        return {
            ...next,
            width,
            height,
        };
    }

    function applyBoxPct(next) {
        const minPct = 8;
        const aspectNext = enforceAspect(next);
        let width = Math.max(minPct, Math.min(100, aspectNext.width));
        let height = Math.max(minPct, Math.min(100, aspectNext.height));

        if (height > 100) {
            height = 100;
            const rect = stageRect();
            width = rect.width && rect.height
                ? height * PROFILE_CROP_ASPECT * (rect.height / rect.width)
                : width;
        }

        const left = Math.max(0, Math.min(100 - width, aspectNext.left));
        const top = Math.max(0, Math.min(100 - height, aspectNext.top));

        box.style.left = `${left}%`;
        box.style.top = `${top}%`;
        box.style.width = `${width}%`;
        box.style.height = `${height}%`;
    }

    function setOcrOverlayVisible(visible) {
        const hiddenClasses = ['opacity-0', 'pointer-events-none'];
        const ocrEls = [
            polygon,
            ...stage.querySelectorAll('[data-intake-corner]'),
        ].filter(Boolean);

        ocrEls.forEach((el) => {
            if (visible) {
                el.classList.remove(...hiddenClasses);
            } else {
                el.classList.add(...hiddenClasses);
            }
        });
    }

    function showPhotoMode() {
        box.classList.remove('hidden');
        setOcrOverlayVisible(false);
    }

    function showOcrMode() {
        box.classList.add('hidden');
        setOcrOverlayVisible(true);
    }

    function validSuggestionBox() {
        if (!suggestion?.available || !suggestion?.box || !img.naturalWidth || !img.naturalHeight) {
            return null;
        }
        const b = suggestion.box;
        const x = Number(b.x);
        const y = Number(b.y);
        const width = Number(b.width);
        const height = Number(b.height);
        if (
            !Number.isFinite(x)
            || !Number.isFinite(y)
            || !Number.isFinite(width)
            || !Number.isFinite(height)
            || x < 0
            || y < 0
            || width < 80
            || height < 80
            || (x + width) > img.naturalWidth
            || (y + height) > img.naturalHeight
        ) {
            return null;
        }
        return { x, y, width, height };
    }

    function applySuggestionOrDefault() {
        showPhotoMode();
        const b = validSuggestionBox();
        if (b) {
            applyBoxPct({
                left: (b.x / img.naturalWidth) * 100,
                top: (b.y / img.naturalHeight) * 100,
                width: (b.width / img.naturalWidth) * 100,
                height: (b.height / img.naturalHeight) * 100,
            });
            setMessage(
                suggestion.auto_saved
                    ? 'Auto-cropped from biodata image. Adjust and save again if needed.'
                    : 'Detected candidate photo area. Adjust if needed, then save.'
            );
            return;
        }

        applyBoxPct({ left: 20, top: 8, width: 30, height: 40 });
        setMessage('Could not auto-detect profile photo. Please adjust crop manually.');
    }

    box.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        showPhotoMode();
        const target = event.target;
        dragMode = target instanceof Element && target.closest('[data-photo-candidate-resize]') ? 'resize' : 'move';
        startClientX = event.clientX;
        startClientY = event.clientY;
        startBox = currentBoxPct();
        box.setPointerCapture(event.pointerId);
    });

    window.addEventListener('pointermove', (event) => {
        if (!dragMode || !startBox) return;

        const rect = stageRect();
        if (!rect.width || !rect.height) return;

        const dxPct = ((event.clientX - startClientX) / rect.width) * 100;
        const dyPct = ((event.clientY - startClientY) / rect.height) * 100;

        if (dragMode === 'move') {
            applyBoxPct({
                ...startBox,
                left: startBox.left + dxPct,
                top: startBox.top + dyPct,
            });
        } else {
            const delta = Math.abs(dxPct) >= Math.abs(dyPct)
                ? dxPct
                : dyPct * PROFILE_CROP_ASPECT * (rect.height / rect.width);
            applyBoxPct({
                ...startBox,
                width: startBox.width + delta,
            });
        }
    });

    window.addEventListener('pointerup', () => {
        dragMode = null;
        startBox = null;
    });

    function cropBlob() {
        if (!img.naturalWidth || !img.naturalHeight) {
            return Promise.reject(new Error('Image not loaded'));
        }

        const boxPct = currentBoxPct();
        const sourceX = Math.round((boxPct.left / 100) * img.naturalWidth);
        const sourceY = Math.round((boxPct.top / 100) * img.naturalHeight);
        const sourceW = Math.round((boxPct.width / 100) * img.naturalWidth);
        const sourceH = Math.round((boxPct.height / 100) * img.naturalHeight);

        if (sourceW < 80 || sourceH < 80) {
            return Promise.reject(new Error(saveBtn.dataset.msgCropTooSmall || 'Crop too small'));
        }

        const canvas = document.createElement('canvas');
        canvas.width = PROFILE_CROP_EXPORT_W;
        canvas.height = PROFILE_CROP_EXPORT_H;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return Promise.reject(new Error('Canvas unavailable'));
        }

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, sourceX, sourceY, sourceW, sourceH, 0, 0, canvas.width, canvas.height);

        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob) resolve(blob);
                else reject(new Error('Image encode failed'));
            }, 'image/jpeg', 0.92);
        });
    }

    autoBtn?.addEventListener('click', applySuggestionOrDefault);
    ocrModeBtn?.addEventListener('click', showOcrMode);

    saveBtn.addEventListener('click', () => {
        showPhotoMode();
        const label = saveBtn.textContent;
        saveBtn.disabled = true;
        saveBtn.textContent = saveBtn.dataset.savingText || 'Saving...';

        cropBlob()
            .then((blob) => {
                const form = new FormData();
                form.append('candidate_image', blob, 'candidate.jpg');
                if (token) form.append('_token', token);

                const headers = {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                };
                if (token) headers['X-CSRF-TOKEN'] = token;

                return fetch(saveUrl, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                    headers,
                });
            })
            .then(async (response) => {
                const raw = await response.text();
                let data = {};
                try {
                    data = raw ? JSON.parse(raw) : {};
                } catch {
                    data = {};
                }
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || `HTTP ${response.status}`);
                }
                if (data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }
                window.location.reload();
            })
            .catch((error) => {
                saveBtn.disabled = false;
                saveBtn.textContent = label;
                toastErr(error?.message || saveBtn.dataset.msgSaveFailed || 'Candidate photo crop save failed.');
            });
    });

    clearBtn?.addEventListener('click', () => {
        const msg = clearBtn.dataset.confirmMessage;
        if (msg && !window.confirm(msg)) return;
        document.getElementById('intake-photo-candidate-clear-form')?.submit();
    });

    function initializeCandidateOverlay() {
        applyBoxPct(currentBoxPct());
        if (validSuggestionBox()) {
            applySuggestionOrDefault();
        } else if (suggestion) {
            setMessage('Could not auto-detect profile photo. Please adjust crop manually.');
        }
    }

    img.addEventListener('load', initializeCandidateOverlay);
    if (img.complete) {
        initializeCandidateOverlay();
    }

    if (!validSuggestionBox()) {
        showOcrMode();
    }
});
