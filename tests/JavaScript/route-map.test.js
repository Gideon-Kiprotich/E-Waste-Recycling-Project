import test from 'node:test';
import assert from 'node:assert/strict';
import { routePoints, enhanceRouteMap } from '../../resources/js/route-map.js';

test('route display rejects missing, nonfinite and out-of-range points without substituting positions', () => {
    for (const value of ['{}', '[]', '[[null,36],[0,0]]', '[[91,36],[0,0]]', '[["NaN",36],[0,0]]']) assert.throws(() => routePoints(value));
    assert.deepEqual(routePoints('[["-1.28","36.82"],[0,0]]'), [[-1.28, 36.82], [0, 0]]);
});

test('route map is opt-in and provider failure keeps the ordered-list fallback', async () => {
    let click, imports = 0;
    const button = { hidden: true, addEventListener(_, handler) { click = handler; } };
    const panel = { hidden: true };
    const status = { textContent: '' };
    const root = { dataset: { mapEnabled: 'true', points: '[[0,0],[1,1]]' }, querySelector(selector) { return selector === 'button' ? button : selector === '[role="status"]' ? status : panel; } };
    enhanceRouteMap(root, async () => { imports++; throw new Error('Synthetic provider failure'); });
    assert.equal(imports, 0);
    assert.equal(button.hidden, false);
    await click();
    assert.equal(imports, 1);
    assert.equal(panel.hidden, true);
    assert.match(status.textContent, /complete ordered list/);
    assert.match(status.textContent, /no route was changed/);
});

test('disabled map configuration starts no external request', () => {
    const button = {}, status = {};
    const root = { dataset: { mapEnabled: 'false' }, querySelector(selector) { return selector === 'button' ? button : status; } };
    enhanceRouteMap(root, () => assert.fail('No provider call allowed'));
    assert.equal(button.disabled, true);
    assert.match(status.textContent, /Maps are disabled/);
});

test('tile failure preserves the ordered-list recovery and map never captures mobile dragging or wheel scrolling', async () => {
    let click, tileError, options;
    const button = { addEventListener(_, handler) { click = handler; } };
    const panel = { hidden: true }, status = {};
    const root = { dataset: { mapEnabled: 'true', points: '[[0,0],[1,1]]', tileUrl: 'https://tiles.example.test/{z}/{x}/{y}.png' }, querySelector(selector) { return selector === 'button' ? button : selector === '[role="status"]' ? status : panel; } };
    const map = { attributionControl: { addAttribution() {} }, fitBounds() {} };
    const tiles = { on(event, handler) { assert.equal(event, 'tileerror'); tileError = handler; } };
    const L = {
        Browser: { mobile: true },
        map(_, value) { options = value; return map; },
        tileLayer(url) { assert.equal(url, root.dataset.tileUrl); return { addTo() { return tiles; } }; },
        polyline() { return { addTo() { return { getBounds() { return { pad() { return {}; } }; } }; } }; },
        circleMarker() { return { addTo() {} }; },
    };
    const previous = globalThis.document;
    globalThis.document = { createElement() { return { outerHTML: '<a>Safe attribution</a>' }; } };
    try {
        enhanceRouteMap(root, async () => L);
        await click();
        assert.equal(options.dragging, false);
        assert.equal(options.touchZoom, false);
        assert.equal(options.scrollWheelZoom, false);
        assert.equal(options.keyboard, false);
        assert.match(status.textContent, /not road navigation/);
        tileError();
        assert.match(status.textContent, /Background tiles are unavailable/);
        assert.match(status.textContent, /ordered list/);
    } finally { globalThis.document = previous; }
});
