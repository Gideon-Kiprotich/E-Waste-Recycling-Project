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
