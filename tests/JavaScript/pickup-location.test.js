import test from 'node:test';
import assert from 'node:assert/strict';
import { readPoint, requestLocation } from '../../resources/js/pickup-location.js';

test('coordinate parsing never substitutes a centre for empty, invalid or out-of-range values', () => {
    for (const values of [[null, null], [[], []], ['', ''], [' ', '36'], ['bad', '36'], ['91', '36'], ['-1', '181'], ['Infinity', '36']]) {
        assert.equal(readPoint(...values), null);
    }
    assert.deepEqual(readPoint('-1.2800001', '36.82'), { lat: -1.28, lng: 36.82 });
    assert.deepEqual(readPoint('0', '0'), { lat: 0, lng: 0 });
});

test('location is requested only on invocation, with timeout and approximate accuracy', () => {
    let calls = 0, starts = 0, selection, feedback;
    const browser = { isSecureContext: true, navigator: { geolocation: {
        getCurrentPosition(success, failure, options) {
            calls++;
            assert.deepEqual(options, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
            success({ coords: { latitude: -1.28, longitude: 36.82, accuracy: 1123.4 } });
        },
    } } };
    assert.equal(calls, 0);
    requestLocation(browser, (point) => { selection = point; }, (text) => { feedback = text; }, () => { starts++; });
    assert.equal(calls, 1);
    assert.equal(starts, 1);
    assert.deepEqual(selection, { lat: -1.28, lng: 36.82 });
    assert.match(feedback, /approximately ±1124 metres/);
    assert.match(feedback, /may be inaccurate/);
});

test('denial, unavailability and timeout never select a replacement point or retry permission', () => {
    for (const code of [1, 2, 3, 999]) {
        let calls = 0, message;
        const browser = { isSecureContext: true, navigator: { geolocation: {
            getCurrentPosition(success, failure) { calls++; failure({ code }); },
        } } };
        requestLocation(browser, () => assert.fail('A failed request must not select a point'), (text) => { message = text; }, () => {});
        assert.equal(calls, 1);
        assert.match(message, /Select a point on the map or use Advanced/);
        assert.match(message, /No location was substituted/);
    }
});

test('unsupported and insecure browsers explain manual alternatives without requesting location', () => {
    for (const browser of [{ isSecureContext: false, navigator: {} }, { isSecureContext: true, navigator: {} }]) {
        let message;
        requestLocation(browser, () => assert.fail(), (text) => { message = text; }, () => assert.fail('Must not request permission'));
        assert.match(message, /Select a point on the map or use Advanced/);
    }
});

test('invalid reported locations and browser restrictions produce safe fallback messages', () => {
    for (const coords of [{ latitude: null, longitude: null, accuracy: 10 }, { latitude: 91, longitude: 36, accuracy: 10 }, { latitude: -1, longitude: 36, accuracy: NaN }, { latitude: -1, longitude: 36, accuracy: null }]) {
        let message;
        requestLocation({ isSecureContext: true, navigator: { geolocation: {
            getCurrentPosition(success) { success({ coords }); },
        } } }, () => assert.fail(), (text) => { message = text; }, () => {});
        assert.match(message, /unusable location/);
    }
    let message;
    requestLocation({ isSecureContext: true, navigator: { geolocation: {
        getCurrentPosition() { throw new Error('restricted'); },
    } } }, () => assert.fail(), (text) => { message = text; }, () => {});
    assert.match(message, /restricted/);
});

const { enhancePickupLocation } = await import('../../resources/js/pickup-location.js');
function siteFixture() {
    const element = (value = '') => ({ value, textContent: '', disabled: false, open: true, hidden: false, required: true, handlers: {},
        addEventListener(type, callback) { this.handlers[type] = callback; }, hasAttribute() { return false; } });
    const nodes = Object.fromEntries(['[name="latitude"]','[name="longitude"]','[data-site-confirmation]','[data-manual-location]','[data-point-status]','[data-location-message]','[data-location-accuracy]','[data-map-panel]','[data-load-map]','[data-geolocate]','[data-map-controls]','[data-use-centre]'].map((selector) => [selector, element()]));
    nodes['[name="latitude"]'].value = '-1.280000';
    nodes['[name="longitude"]'].value = '36.820000';
    nodes['[data-site-confirmation]'].value = 'synthetic-server-confirmation';
    const root = { dataset: { locationKind: 'site', mapEnabled: 'false', confirmed: 'true' },
        querySelector(selector) { return nodes[selector] ?? null; }, querySelectorAll() { return []; }, closest() { return { querySelector() { return null; } }; } };
    return { root, nodes };
}

test('site enhancement retains manual selection on provider-disabled fallback and invalidates confirmation on coordinate edits', () => {
    const { root, nodes } = siteFixture();
    enhancePickupLocation(root, { isSecureContext: true, navigator: {} });
    assert.equal(nodes['[data-manual-location]'].open, true);
    assert.match(nodes['[data-location-message]'].textContent, /manual latitude/);
    assert.equal(nodes['[data-site-confirmation]'].value, 'synthetic-server-confirmation');
    assert.equal(nodes['[name="latitude"]'].value, '-1.280000');
    nodes['[name="latitude"]'].value = '-1.281000';
    nodes['[name="latitude"]'].handlers.input();
    assert.equal(nodes['[data-site-confirmation]'].value, '');
    assert.equal(root.dataset.confirmed, 'false');
    assert.match(nodes['[data-point-status]'].textContent, /Confirm site entrance/);
});

test('explicit site geolocation synchronizes six-decimal inputs and warns about the device versus the facility', () => {
    const { root, nodes } = siteFixture();
    let calls = 0;
    enhancePickupLocation(root, { isSecureContext: true, navigator: { geolocation: {
        getCurrentPosition(success) { calls++; success({ coords: { latitude: -1.29, longitude: 36.83, accuracy: 275.2 } }); },
    } } });
    assert.equal(calls, 0);
    nodes['[data-geolocate]'].handlers.click();
    assert.equal(calls, 1);
    assert.equal(nodes['[data-geolocate]'].disabled, true);
    assert.equal(nodes['[name="latitude"]'].value, '-1.290000');
    assert.equal(nodes['[name="longitude"]'].value, '36.830000');
    assert.equal(nodes['[data-site-confirmation]'].value, '');
    assert.match(nodes['[data-location-accuracy]'].textContent, /approximately ±276 metres/);
    assert.match(nodes['[data-location-accuracy]'].textContent, /locates your device/);
});

test('site permission denial retains the original confirmed location without replacing it', () => {
    const { root, nodes } = siteFixture();
    enhancePickupLocation(root, { isSecureContext: true, navigator: { geolocation: {
        getCurrentPosition(success, failure) { failure({ code: 1 }); },
    } } });
    nodes['[data-geolocate]'].handlers.click();
    assert.equal(nodes['[data-site-confirmation]'].value, 'synthetic-server-confirmation');
    assert.equal(nodes['[name="latitude"]'].value, '-1.280000');
    assert.match(nodes['[data-location-message]'].textContent, /permission was denied/);
});
