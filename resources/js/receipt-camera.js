export function receiptCode(value, origin) {
    if (typeof value !== 'string') return null;
    if (/^EWC-[A-F0-9]{32}$/.test(value.trim())) return value.trim();
    try {
        const url = new URL(value);
        if (url.origin !== origin || url.search || url.hash) return null;
        return url.pathname.match(/^\/lookup\/(EWC-[A-F0-9]{32})$/)?.[1] ?? null;
    } catch { return null; }
}

export function cameraFailure(error) {
    if (error?.name === 'NotAllowedError') return 'Camera permission denied. Enter the printed EWC code manually; no receipt was recorded.';
    return 'Camera unavailable or scanning failed. Enter the printed EWC code manually; no receipt was recorded.';
}

export function mountReceiptCamera(root = document) {
    const form = root.querySelector('[data-receipt-lookup]');
    if (!root.querySelector('[data-error-summary]')) root.querySelector('[data-receipt-selected]')?.focus();
    if (!form) return;
    const start = form.querySelector('[data-camera-start]');
    start.hidden = false;
    const stop = form.querySelector('[data-camera-stop]');
    const status = form.querySelector('[data-camera-status]');
    const video = form.querySelector('video');
    const input = form.querySelector('[name="code"]');
    let stream, timer, running = false, attempted = false;
    const close = () => {
        running = false;
        clearTimeout(timer);
        stream?.getTracks().forEach(track => track.stop());
        stream = null; video.srcObject = null; video.hidden = true; stop.hidden = true;
    };
    stop.addEventListener('click', () => { close(); status.textContent = 'Camera stopped. Manual entry remains available.'; input.focus(); });
    start.addEventListener('click', async () => {
        if (attempted) { status.textContent = 'Use manual entry for this manifest. Camera permission is not requested again.'; input.focus(); return; }
        attempted = true;
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || !window.BarcodeDetector) {
            status.textContent = 'Camera scanning is unavailable in this browser or insecure context. Enter the printed EWC code manually.'; input.focus(); return;
        }
        try {
            const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            video.srcObject = stream; video.hidden = false; stop.hidden = false;
            await video.play(); running = true;
            status.textContent = 'Point the camera at the EWC label. Scanning only selects an item; receipt requires separate confirmation.';
            const scan = async () => {
                if (!running) return;
                try {
                    const results = await detector.detect(video);
                    if (!running) return;
                    for (const result of results) {
                        const code = receiptCode(result.rawValue, window.location.origin);
                        if (code) { input.value = code; close(); status.textContent = 'Code read. Select label to check it against this manifest.'; input.focus(); return; }
                    }
                    timer = setTimeout(scan, 250);
                } catch (error) { close(); status.textContent = cameraFailure(error); input.focus(); }
            };
            await scan();
        } catch (error) { close(); status.textContent = cameraFailure(error); input.focus(); }
    });
    window.addEventListener('pagehide', close, { once: true });
}
