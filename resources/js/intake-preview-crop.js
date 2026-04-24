import PerspT from 'perspective-transform';

document.addEventListener('DOMContentLoaded', () => {
    const img = document.getElementById('intake-manual-crop-img');
    const stage = document.getElementById('intake-manual-crop-stage');
    const polygon = document.getElementById('intake-manual-crop-polygon');
    if (!img || !(img instanceof HTMLImageElement) || !stage || !polygon) {
        return;
    }

    const toastErr = (msg) => {
        const m = String(msg || '').trim();
        if (!m) return;
        if (window.toastr && typeof window.toastr.error === 'function') {
            window.toastr.error(m);
        } else {
            window.alert(m);
        }
    };

    const saveUrl = img.dataset.saveUrl;
    if (!saveUrl) {
        return;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const handleEls = {};
    stage.querySelectorAll('[data-intake-corner]').forEach((el) => {
        const k = el.getAttribute('data-intake-corner');
        if (k) handleEls[k] = el;
    });

    const requiredKeys = ['tl', 'tr', 'br', 'bl'];
    for (const k of requiredKeys) {
        if (!handleEls[k]) {
            return;
        }
    }

    let suggestedCrop = null;
    const sugEl = document.getElementById('intake-auto-crop-suggestion');
    if (sugEl && sugEl.textContent) {
        try {
            suggestedCrop = JSON.parse(sugEl.textContent.trim());
        } catch {
            suggestedCrop = null;
        }
    }

    /**
     * IMPORTANT: declare all state BEFORE any `load` listeners.
     * Some browsers can fire `load` synchronously when attaching the listener; if `let currentW`
     * etc. are still in the temporal dead zone, the handler throws and the rest of this module
     * never runs (polygon stays at default 0–100px square, corners won't drag).
     */
    const MAX_OUTPUT_SIDE = 2400;

    let baseImg = new Image();
    let baseW = 0;
    let baseH = 0;

    let currentW = 0;
    let currentH = 0;
    let rotationDegCW = 0;
    let isInitialized = false;

    const pts = {
        tl: { x: 0, y: 0 },
        tr: { x: 0, y: 0 },
        br: { x: 0, y: 0 },
        bl: { x: 0, y: 0 },
    };

    const rotateLeftBtn = document.getElementById('intake-crop-rotate-left');
    const rotateRightBtn = document.getElementById('intake-crop-rotate-right');
    const resetBtn = document.getElementById('intake-crop-reset');
    const saveBtn = document.getElementById('intake-crop-save');
    const clearBtn = document.getElementById('intake-crop-clear');

    document.getElementById('intake-crop-rotate-fine-ccw')?.setAttribute('disabled', 'true');
    document.getElementById('intake-crop-rotate-fine-cw')?.setAttribute('disabled', 'true');

    const overlayEls = [polygon, ...Object.values(handleEls)];
    overlayEls.forEach((el) => {
        if (el) {
            el.classList.add('opacity-0', 'pointer-events-none');
        }
    });

    let bootstrapAttempts = 0;
    const maxBootstrapAttempts = 120;

    function revealOverlay() {
        overlayEls.forEach((el) => {
            if (el) {
                el.classList.remove('opacity-0', 'pointer-events-none');
            }
        });
    }

    function bootstrapWhenReady() {
        if (isInitialized) {
            revealOverlay();
            return;
        }
        bootstrapAttempts += 1;
        if (bootstrapAttempts > maxBootstrapAttempts) {
            revealOverlay();
            return;
        }
        initFromImgIfNeeded();
        if (isInitialized) {
            syncUI();
            revealOverlay();
            return;
        }
        requestAnimationFrame(bootstrapWhenReady);
    }

    function initFromImgIfNeeded() {
        if (isInitialized) return;
        if (!img.naturalWidth || !img.naturalHeight) return;

        currentW = img.naturalWidth;
        currentH = img.naturalHeight;

        initDefaultPoints();
        isInitialized = true;
    }

    function bootstrapFromImage() {
        initFromImgIfNeeded();
        syncUI();
        if (isInitialized) {
            revealOverlay();
        }
    }

    img.addEventListener('load', () => {
        bootstrapFromImage();
    });

    function clamp(v, min, max) {
        if (Number.isNaN(v)) return min;
        return Math.max(min, Math.min(max, v));
    }

    function hypot(dx, dy) {
        return Math.sqrt(dx * dx + dy * dy);
    }

    function renderRotation90cw(sourceImg, degCW) {
        const w = sourceImg.naturalWidth;
        const h = sourceImg.naturalHeight;
        const d = ((degCW % 360) + 360) % 360;

        const canvas = document.createElement('canvas');
        if (d === 90 || d === 270) {
            canvas.width = h;
            canvas.height = w;
        } else {
            canvas.width = w;
            canvas.height = h;
        }

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return null;
        }

        ctx.save();
        switch (d) {
            case 0:
                ctx.drawImage(sourceImg, 0, 0);
                break;
            case 90:
                ctx.translate(canvas.width, 0);
                ctx.rotate(Math.PI / 2);
                ctx.drawImage(sourceImg, 0, 0);
                break;
            case 180:
                ctx.translate(canvas.width, canvas.height);
                ctx.rotate(Math.PI);
                ctx.drawImage(sourceImg, 0, 0);
                break;
            case 270:
                ctx.translate(0, canvas.height);
                ctx.rotate(-Math.PI / 2);
                ctx.drawImage(sourceImg, 0, 0);
                break;
            default:
                ctx.drawImage(sourceImg, 0, 0);
        }
        ctx.restore();

        return canvas.toDataURL('image/png');
    }

    function syncUI() {
        const w = currentW;
        const h = currentH;
        if (!w || !h) return;

        for (const k of requiredKeys) {
            const el = handleEls[k];
            const px = clamp(pts[k].x, 0, w);
            const py = clamp(pts[k].y, 0, h);
            el.style.left = `${(px / w) * 100}%`;
            el.style.top = `${(py / h) * 100}%`;
        }

        const stageRect = stage.getBoundingClientRect();
        const sw = stageRect.width;
        const sh = stageRect.height;
        if (!sw || !sh) return;

        const pToSvg = (p) => {
            const x = (p.x / w) * sw;
            const y = (p.y / h) * sh;
            return `${x},${y}`;
        };

        polygon.setAttribute('points', [pToSvg(pts.tl), pToSvg(pts.tr), pToSvg(pts.br), pToSvg(pts.bl)].join(' '));
    }

    function initDefaultPoints() {
        const w = currentW;
        const h = currentH;
        if (!w || !h) return;

        const s = suggestedCrop;
        if (
            s &&
            s.tl &&
            s.tr &&
            s.br &&
            s.bl &&
            typeof s.tl.x === 'number' &&
            typeof s.tl.y === 'number' &&
            typeof s.tr.x === 'number' &&
            typeof s.tr.y === 'number' &&
            typeof s.br.x === 'number' &&
            typeof s.br.y === 'number' &&
            typeof s.bl.x === 'number' &&
            typeof s.bl.y === 'number'
        ) {
            const maxX = w - 1;
            const maxY = h - 1;
            pts.tl.x = clamp(Math.round(s.tl.x), 0, maxX);
            pts.tl.y = clamp(Math.round(s.tl.y), 0, maxY);
            pts.tr.x = clamp(Math.round(s.tr.x), 0, maxX);
            pts.tr.y = clamp(Math.round(s.tr.y), 0, maxY);
            pts.br.x = clamp(Math.round(s.br.x), 0, maxX);
            pts.br.y = clamp(Math.round(s.br.y), 0, maxY);
            pts.bl.x = clamp(Math.round(s.bl.x), 0, maxX);
            pts.bl.y = clamp(Math.round(s.bl.y), 0, maxY);
        } else {
            pts.tl.x = w * 0.06;
            pts.tl.y = h * 0.06;
            pts.tr.x = w * 0.94;
            pts.tr.y = h * 0.06;
            pts.br.x = w * 0.94;
            pts.br.y = h * 0.94;
            pts.bl.x = w * 0.06;
            pts.bl.y = h * 0.94;
        }

        syncUI();
    }

    function rotatePointsCW90() {
        const oldW = currentW;
        const oldH = currentH;
        const newW = oldH;
        const newH = oldW;

        function rot(p) {
            return {
                x: oldH - 1 - p.y,
                y: p.x,
            };
        }

        pts.tl = rot(pts.tl);
        pts.tr = rot(pts.tr);
        pts.br = rot(pts.br);
        pts.bl = rot(pts.bl);

        currentW = newW;
        currentH = newH;
    }

    function rotatePointsCCW90() {
        const oldW = currentW;
        const oldH = currentH;
        const newW = oldH;
        const newH = oldW;

        function rot(p) {
            return {
                x: p.y,
                y: oldW - 1 - p.x,
            };
        }

        pts.tl = rot(pts.tl);
        pts.tr = rot(pts.tr);
        pts.br = rot(pts.br);
        pts.bl = rot(pts.bl);

        currentW = newW;
        currentH = newH;
    }

    function applyRotation(degStep) {
        // Only rotate once `baseImg` is actually decoded (otherwise naturalWidth/naturalHeight can be 0).
        if (!baseImg.naturalWidth || !baseImg.naturalHeight) return;

        if (degStep === 90) {
            rotationDegCW = (rotationDegCW + 90) % 360;
            rotatePointsCW90();
        } else if (degStep === -90) {
            rotationDegCW = (rotationDegCW + 270) % 360;
            rotatePointsCCW90();
        } else {
            return;
        }

        const rotatedSrc = renderRotation90cw(baseImg, rotationDegCW);
        if (!rotatedSrc) return;

        img.src = rotatedSrc;
        syncUI();
    }

    let draggingKey = null;
    stage.addEventListener('pointerdown', (e) => {
        const target = e.target;
        if (!target || !(target instanceof Element)) return;
        const handle = target.closest('[data-intake-corner]');
        if (!(handle instanceof HTMLElement)) return;
        const k = handle.getAttribute('data-intake-corner');
        if (!k) return;
        e.preventDefault();
        draggingKey = k;
        handle.setPointerCapture(e.pointerId);
    });

    function updateDragFromPointer(e) {
        if (!draggingKey) return;
        if (!currentW || !currentH) return; // not initialized yet

        const stageRect = stage.getBoundingClientRect();
        if (!stageRect.width || !stageRect.height) return;

        const nx = (e.clientX - stageRect.left) / stageRect.width;
        const ny = (e.clientY - stageRect.top) / stageRect.height;

        pts[draggingKey].x = clamp(nx * currentW, 0, currentW);
        pts[draggingKey].y = clamp(ny * currentH, 0, currentH);
        syncUI();
    }

    // Window-level handlers keep drag working even if pointer leaves stage box.
    window.addEventListener('pointermove', updateDragFromPointer);
    window.addEventListener('pointerup', () => {
        draggingKey = null;
    });
    window.addEventListener('pointercancel', () => {
        draggingKey = null;
    });

    function collectPointsForWarp() {
        const w = currentW;
        const h = currentH;
        const out = {};
        for (const k of requiredKeys) {
            out[k] = {
                x: clamp(pts[k].x, 0, w),
                y: clamp(pts[k].y, 0, h),
            };
        }
        return out;
    }

    /**
     * Bilinear sample; outside source → white (OCR-friendly).
     */
    function sampleBilinear(s, sw, sh, sx, sy) {
        if (sx < 0 || sy < 0 || sx >= sw || sy >= sh) {
            return [255, 255, 255, 255];
        }
        const x0 = Math.floor(sx);
        const y0 = Math.floor(sy);
        const x1 = Math.min(x0 + 1, sw - 1);
        const y1 = Math.min(y0 + 1, sh - 1);
        const fx = sx - x0;
        const fy = sy - y0;
        const idx = (xx, yy) => (yy * sw + xx) * 4;
        const i00 = idx(x0, y0);
        const i10 = idx(x1, y0);
        const i01 = idx(x0, y1);
        const i11 = idx(x1, y1);
        const lerp = (a, b, t) => a + (b - a) * t;
        const r = lerp(lerp(s[i00], s[i10], fx), lerp(s[i01], s[i11], fx), fy);
        const g = lerp(lerp(s[i00 + 1], s[i10 + 1], fx), lerp(s[i01 + 1], s[i11 + 1], fx), fy);
        const b = lerp(lerp(s[i00 + 2], s[i10 + 2], fx), lerp(s[i01 + 2], s[i11 + 2], fx), fy);
        const a = lerp(lerp(s[i00 + 3], s[i10 + 3], fx), lerp(s[i01 + 3], s[i11 + 3], fx), fy);
        return [Math.round(r), Math.round(g), Math.round(b), Math.round(a)];
    }

    /**
     * Perspective-correct the quad to a straight rectangle (white outside quad in output).
     */
    function warpQuadToRectangleBlob(sourceImgEl) {
        const sw = sourceImgEl.naturalWidth;
        const sh = sourceImgEl.naturalHeight;
        if (!sw || !sh) {
            return Promise.reject(new Error('Image not loaded'));
        }

        const p = collectPointsForWarp();
        const tl = p.tl;
        const tr = p.tr;
        const br = p.br;
        const bl = p.bl;

        const widthA = hypot(tr.x - tl.x, tr.y - tl.y);
        const widthB = hypot(br.x - bl.x, br.y - bl.y);
        const heightA = hypot(bl.x - tl.x, bl.y - tl.y);
        const heightB = hypot(br.x - tr.x, br.y - tr.y);

        let destW = Math.round(Math.max(1, Math.max(widthA, widthB)));
        let destH = Math.round(Math.max(1, Math.max(heightA, heightB)));

        if (!Number.isFinite(destW) || !Number.isFinite(destH) || destW < 1 || destH < 1) {
            return Promise.reject(new Error('Invalid corner geometry'));
        }

        const maxSide = Math.max(destW, destH);
        if (maxSide > MAX_OUTPUT_SIDE) {
            const sc = MAX_OUTPUT_SIDE / maxSide;
            destW = Math.max(1, Math.round(destW * sc));
            destH = Math.max(1, Math.round(destH * sc));
        }

        const maxPixels = 12_000_000;
        if (destW * destH > maxPixels) {
            const sc = Math.sqrt(maxPixels / (destW * destH));
            destW = Math.max(1, Math.round(destW * sc));
            destH = Math.max(1, Math.round(destH * sc));
        }

        const srcCanvas = document.createElement('canvas');
        srcCanvas.width = sw;
        srcCanvas.height = sh;
        const sctx = srcCanvas.getContext('2d');
        if (!sctx) {
            return Promise.reject(new Error('No 2D context'));
        }
        sctx.drawImage(sourceImgEl, 0, 0);
        const srcData = sctx.getImageData(0, 0, sw, sh);
        const s = srcData.data;

        const srcPts = [tl.x, tl.y, tr.x, tr.y, br.x, br.y, bl.x, bl.y];
        const dstPts = [0, 0, destW, 0, destW, destH, 0, destH];

        let persp;
        try {
            persp = new PerspT(srcPts, dstPts);
        } catch {
            return Promise.reject(new Error('Invalid corner geometry'));
        }

        const outCanvas = document.createElement('canvas');
        outCanvas.width = destW;
        outCanvas.height = destH;
        const octx = outCanvas.getContext('2d');
        if (!octx) {
            return Promise.reject(new Error('No 2D context'));
        }
        const outData = octx.createImageData(destW, destH);
        const o = outData.data;

        for (let y = 0; y < destH; y++) {
            for (let x = 0; x < destW; x++) {
                const mapped = persp.transformInverse(x, y);
                const sx = mapped[0];
                const sy = mapped[1];
                const [r, g, b, a] = sampleBilinear(s, sw, sh, sx, sy);
                const i = (y * destW + x) * 4;
                o[i] = r;
                o[i + 1] = g;
                o[i + 2] = b;
                o[i + 3] = a;
            }
        }
        octx.putImageData(outData, 0, 0);

        return new Promise((resolve, reject) => {
            outCanvas.toBlob(
                (blob) => {
                    if (blob) resolve(blob);
                    else reject(new Error('PNG encode failed'));
                },
                'image/png',
                0.92,
            );
        });
    }

    saveBtn?.addEventListener('click', () => {
        // Do not gate on baseW/baseH — those are only for rotation source; corners use currentW/H.
        if (!currentW || !currentH) return;

        const p = collectPointsForWarp();
        const width =
            Math.abs(p.tr.x - p.tl.x) + Math.abs(p.br.x - p.bl.x);
        const height =
            Math.abs(p.bl.y - p.tl.y) + Math.abs(p.br.y - p.tr.y);
        if (width < 20 || height < 20) {
            toastErr(img.dataset.msgCorners || '');
            return;
        }

        const label = saveBtn.textContent;
        const savingText = saveBtn.dataset.savingText ?? '…';
        saveBtn.disabled = true;
        saveBtn.textContent = savingText;

        warpQuadToRectangleBlob(img)
            .then((blob) => {
                const fd = new FormData();
                fd.append('cropped_image', blob, 'manual.png');
                if (token) fd.append('_token', token);

                const controller = new AbortController();
                const timeoutMs = 120000;
                const timeoutId = window.setTimeout(() => controller.abort(), timeoutMs);

                const hdr = {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                };
                if (token) {
                    hdr['X-CSRF-TOKEN'] = token;
                }

                return fetch(saveUrl, {
                    method: 'POST',
                    body: fd,
                    signal: controller.signal,
                    credentials: 'same-origin',
                    headers: hdr,
                }).then(async (r) => {
                    window.clearTimeout(timeoutId);
                    const raw = await r.text();
                    let data = {};
                    try {
                        data = raw ? JSON.parse(raw) : {};
                    } catch {
                        data = {};
                    }
                    if (!r.ok || !data.ok) {
                        const msg = (data && data.message) || `HTTP ${r.status}`;
                        throw new Error(msg);
                    }
                    if (data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    saveBtn.disabled = false;
                    saveBtn.textContent = label;
                    toastErr(data.message || img.dataset.msgNoRedirect || '');
                });
            })
            .catch((err) => {
                saveBtn.disabled = false;
                saveBtn.textContent = label;
                toastErr(
                    err && err.message
                        ? err.message
                        : img.dataset.msgSaveFailed || '',
                );
            });
    });

    rotateLeftBtn?.addEventListener('click', () => applyRotation(-90));
    rotateRightBtn?.addEventListener('click', () => applyRotation(90));
    resetBtn?.addEventListener('click', () => {
        rotationDegCW = 0;
        const naturalW = baseImg.naturalWidth || img.naturalWidth || 0;
        const naturalH = baseImg.naturalHeight || img.naturalHeight || 0;

        if (baseImg.naturalWidth && baseImg.naturalHeight) {
            img.src = renderRotation90cw(baseImg, 0) || img.src;
        }

        currentW = naturalW;
        currentH = naturalH;
        initDefaultPoints();
    });

    clearBtn?.addEventListener('click', () => {
        const msg = clearBtn.dataset.confirmMessage;
        if (msg && !window.confirm(msg)) return;
        const form = document.getElementById('intake-manual-crop-clear-form');
        if (form) form.submit();
    });

    baseImg.onload = () => {
        baseW = baseImg.naturalWidth || 0;
        baseH = baseImg.naturalHeight || 0;
        // Do not re-initialize corners here; we initialize from the visible `img`.
    };
    baseImg.onerror = () => {};

    baseImg.src = img.src;

    // Cached images: `load` may not fire; decode / rAF until natural dimensions exist.
    queueMicrotask(() => bootstrapWhenReady());
    if (typeof img.decode === 'function') {
        img.decode().then(() => bootstrapWhenReady()).catch(() => bootstrapWhenReady());
    }
});
