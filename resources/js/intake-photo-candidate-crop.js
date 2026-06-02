document.addEventListener('DOMContentLoaded', () => {
    const img = document.getElementById('intake-photo-candidate-img');
    const stage = document.getElementById('intake-photo-candidate-stage');
    const box = document.getElementById('intake-photo-candidate-box');
    const saveBtn = document.getElementById('intake-photo-candidate-save');
    const clearBtn = document.getElementById('intake-photo-candidate-clear');

    if (!img || !(img instanceof HTMLImageElement) || !stage || !box || !saveBtn) {
        return;
    }

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
    const saveUrl = img.dataset.saveUrl;
    if (!saveUrl) return;

    let dragMode = null;
    let startClientX = 0;
    let startClientY = 0;
    let startBox = null;

    function stageRect() {
        return stage.getBoundingClientRect();
    }

    function currentBoxPct() {
        return {
            left: parseFloat(box.style.left || '25'),
            top: parseFloat(box.style.top || '15'),
            width: parseFloat(box.style.width || '35'),
            height: parseFloat(box.style.height || '35'),
        };
    }

    function applyBoxPct(next) {
        const minPct = 8;
        const width = Math.max(minPct, Math.min(100, next.width));
        const height = Math.max(minPct, Math.min(100, next.height));
        const left = Math.max(0, Math.min(100 - width, next.left));
        const top = Math.max(0, Math.min(100 - height, next.top));

        box.style.left = `${left}%`;
        box.style.top = `${top}%`;
        box.style.width = `${width}%`;
        box.style.height = `${height}%`;
    }

    box.addEventListener('pointerdown', (event) => {
        event.preventDefault();
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
            applyBoxPct({
                ...startBox,
                width: startBox.width + dxPct,
                height: startBox.height + dyPct,
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
            return Promise.reject(new Error(img.dataset.msgCropTooSmall || 'Crop too small'));
        }

        const maxSide = 900;
        const scale = Math.min(maxSide / Math.max(sourceW, sourceH), 1);
        const outW = Math.max(1, Math.round(sourceW * scale));
        const outH = Math.max(1, Math.round(sourceH * scale));

        const canvas = document.createElement('canvas');
        canvas.width = outW;
        canvas.height = outH;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return Promise.reject(new Error('Canvas unavailable'));
        }

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, outW, outH);
        ctx.drawImage(img, sourceX, sourceY, sourceW, sourceH, 0, 0, outW, outH);

        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob) resolve(blob);
                else reject(new Error('Image encode failed'));
            }, 'image/jpeg', 0.88);
        });
    }

    saveBtn.addEventListener('click', () => {
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
                toastErr(error?.message || img.dataset.msgSaveFailed || 'Candidate photo crop save failed.');
            });
    });

    clearBtn?.addEventListener('click', () => {
        const msg = clearBtn.dataset.confirmMessage;
        if (msg && !window.confirm(msg)) return;
        document.getElementById('intake-photo-candidate-clear-form')?.submit();
    });
});
