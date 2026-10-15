import test from 'node:test';
import assert from 'node:assert/strict';
import { receiptCode, cameraFailure } from '../../resources/js/receipt-camera.js';
const code = `EWC-${'A'.repeat(32)}`;
test('manual and existing same-origin labels are parsed without following URLs', () => {
    assert.equal(receiptCode(code, 'https://example.test'), code);
    assert.equal(receiptCode(`https://example.test/lookup/${code}`, 'https://example.test'), code);
    for (const value of [`https://other.test/lookup/${code}`, `https://example.test/lookup/${code}?private=x`, `${code}x`, null, `https://example.test/lookup/${code}#x`]) assert.equal(receiptCode(value, 'https://example.test'), null);
});
test('camera failures offer manual entry and make no receipt claim', () => {
    assert.match(cameraFailure({ name: 'NotAllowedError' }), /denied.*manually.*no receipt/);
    assert.match(cameraFailure(new Error()), /unavailable.*manually.*no receipt/);
});

import { mountReceiptCamera } from '../../resources/js/receipt-camera.js';
function cameraFixture() {
    const controls = {};
    for (const name of ['start', 'stop']) controls[name] = { addEventListener(_, fn) { this.click = fn; }, hidden: false };
    controls.status = { textContent: '' };
    controls.input = { value: '', focus() { this.focused = true; } };
    controls.video = { hidden: true, async play() {} };
    const selectors = { '[data-camera-start]': controls.start, '[data-camera-stop]': controls.stop, '[data-camera-status]': controls.status, 'video': controls.video, '[name="code"]': controls.input };
    const form = { querySelector: selector => selectors[selector] };
    controls.root = { querySelector: selector => selector === '[data-receipt-lookup]' ? form : null };
    return controls;
}
async function withCameraEnvironment(windowValue, mediaDevices, callback) {
    const oldWindow = globalThis.window;
    const oldNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    globalThis.window = { addEventListener() {}, location: { origin: 'https://example.test' }, ...windowValue };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { mediaDevices } });
    try { await callback(); }
    finally { globalThis.window = oldWindow; if (oldNavigator) Object.defineProperty(globalThis, 'navigator', oldNavigator); else delete globalThis.navigator; }
}
test('unsupported scanner leaves manual entry and never requests a camera', async () => {
    await withCameraEnvironment({ isSecureContext: false }, { getUserMedia() { assert.fail('No permission request allowed'); } }, async () => {
        const f = cameraFixture(); mountReceiptCamera(f.root);
        assert.equal(f.status.textContent, '');
        await f.start.click();
        assert.match(f.status.textContent, /manually/);
        assert.equal(f.input.focused, true);
    });
});
test('explicit camera denial is handled once without losing manual input or submitting', async () => {
    let prompts = 0;
    await withCameraEnvironment({ isSecureContext: true, BarcodeDetector: class {} }, { async getUserMedia() { prompts++; throw { name: 'NotAllowedError' }; } }, async () => {
        const f = cameraFixture(); f.input.value = code; mountReceiptCamera(f.root);
        assert.equal(prompts, 0);
        await f.start.click();
        assert.match(f.status.textContent, /denied.*manually/);
        await f.start.click();
        assert.equal(prompts, 1); assert.equal(f.input.value, code); assert.equal(f.video.hidden, true);
    });
});
test('scanning selects only a code, stops the stream and requires a separate label action', async () => {
    let stopped = 0;
    await withCameraEnvironment({ isSecureContext: true, BarcodeDetector: class { async detect() { return [{ rawValue: `https://example.test/lookup/${code}` }]; } } }, { async getUserMedia() { return { getTracks() { return [{ stop() { stopped++; } }]; } }; } }, async () => {
        const f = cameraFixture(); mountReceiptCamera(f.root); await f.start.click();
        assert.equal(f.input.value, code); assert.equal(stopped, 1); assert.equal(f.video.hidden, true);
        assert.match(f.status.textContent, /Select label/);
    });
});
