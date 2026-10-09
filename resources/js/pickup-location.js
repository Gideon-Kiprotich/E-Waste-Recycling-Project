export function readPoint(latitude, longitude) {
    if (![latitude, longitude].every((value) => typeof value === 'string' || typeof value === 'number')) return null;
    if (String(latitude).trim() === '' || String(longitude).trim() === '') return null;
    const lat = Number(latitude), lng = Number(longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    return { lat: Number(lat.toFixed(6)), lng: Number(lng.toFixed(6)) };
}

export function requestLocation(browser, success, message, started, site = false) {
    const alternative = site ? 'manual latitude / longitude entry' : 'Advanced location entry';
    if (!browser.isSecureContext) {
        message(`Browser location requires HTTPS or localhost. Select a point on the map or use ${alternative}.`);
        return;
    }
    if (!browser.navigator.geolocation) {
        message(`This browser does not support location. Select a point on the map or use ${alternative}.`);
        return;
    }
    started();
    try {
        browser.navigator.geolocation.getCurrentPosition((position) => {
            const point = readPoint(position.coords.latitude, position.coords.longitude);
            const accuracy = position.coords.accuracy;
            if (!point || typeof accuracy !== 'number' || !Number.isFinite(accuracy) || accuracy < 0) {
                message('Your browser returned an unusable location. Select a point manually.');
                return;
            }
            success(point, accuracy);
            message(`Browser location accuracy is approximately ±${Math.ceil(accuracy)} metres. It may be inaccurate; check and adjust the ${site ? 'actual facility entrance (your device may be elsewhere)' : 'entrance or meeting point'} before confirming.`);
        }, (error) => {
            const reason = {
                1: 'Location permission was denied.',
                2: 'Your location is unavailable.',
                3: 'The location request timed out.',
            }[error.code] ?? 'Your browser could not provide a location.';
            message(`${reason} Select a point on the map or use ${alternative}. No location was substituted.`);
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    } catch {
        message(`Browser location is restricted. Select a point on the map or use ${alternative}.`);
    }
}

export function enhancePickupLocation(root, browser = window) {
    const site = root.dataset.locationKind === 'site';
    const subject = site ? 'site entrance' : 'pickup point';
    const confirmLabel = site ? 'Confirm site entrance' : 'Confirm pickup point';
    const confirmation = root.querySelector('[data-site-confirmation]');
    const latitude = root.querySelector('[name="latitude"]');
    const longitude = root.querySelector('[name="longitude"]');
    const area = root.closest('form').querySelector('[name="service_area_id"]');
    const manual = root.querySelector('[data-manual-location]');
    const status = root.querySelector('[data-point-status]');
    const message = root.querySelector('[data-location-message]');
    const accuracyMessage = root.querySelector('[data-location-accuracy]');
    const changed = root.querySelector('[data-pickup-changed]');
    const panel = root.querySelector('[data-map-panel]');
    const load = root.querySelector('[data-load-map]');
    const locate = root.querySelector('[data-geolocate]');
    let point = readPoint(latitude.value, longitude.value);
    let map, marker, L, loading;
    const announce = (text) => { message.textContent = text; };
    const invalidate = () => {
        if (changed) changed.disabled = false;
        if (confirmation) confirmation.value = '';
        root.dataset.confirmed = 'false';
        status.textContent = `ⓘ Point changed or not yet confirmed. Press ${confirmLabel} before continuing.`;
        accuracyMessage.textContent = '';
    };
    const showMarker = () => {
        if (!map) return;
        if (!point) {
            if (marker) marker.remove();
            marker = null;
            return;
        }
        if (marker) marker.setLatLng(point);
        else {
            marker = L.marker(point, {
                draggable: true, keyboard: false, title: `Selected ${subject}; use map centre as an alternative to dragging`,
                icon: L.divIcon({ className: 'pickup-pin', html: '<span aria-hidden="true">●</span>', iconSize: [48, 48], iconAnchor: [24, 24] }),
            }).addTo(map);
            marker.on('dragstart', invalidate);
            marker.on('dragend', () => select(marker.getLatLng()));
        }
    };
    const select = (next) => {
        point = readPoint(next.lat, next.lng);
        if (!point) { announce('This point is outside the coordinate range. Select another point.'); return; }
        latitude.value = point.lat.toFixed(6);
        longitude.value = point.lng.toFixed(6);
        invalidate();
        showMarker();
    };
    const fallback = () => {
        manual.open = true;
        announce(`The map is unavailable. Use ${site ? 'manual latitude / longitude entry' : 'Advanced location entry'}, then confirm the point. Your existing selection is retained.`);
    };
    const ensureMap = () => {
        if (map) return Promise.resolve();
        if (loading) return loading;
        if (root.dataset.mapEnabled !== 'true') { fallback(); return Promise.resolve(); }
        loading = import('./pickup-map.js').then((module) => {
            L = module.default;
            panel.hidden = false;
            map = L.map(root.querySelector('[data-map]'), {
                scrollWheelZoom: false, dragging: !L.Browser.mobile, touchZoom: false,
                keyboard: true, doubleClickZoom: false, worldCopyJump: true,
            }).setView(point ?? { lat: -1.275, lng: 36.825 }, point ? 16 : 12);
            // Only image tiles send the site origin; private page paths keep no-referrer.
            const tiles = L.tileLayer(root.dataset.tileUrl, { referrerPolicy: 'origin', maxZoom: 19, updateWhenIdle: true, keepBuffer: 0 });
            let failed = false;
            tiles.on('tileerror', () => {
                if (!failed) { failed = true; fallback(); }
            });
            tiles.addTo(map);
            const link = document.createElement('a');
            link.textContent = root.dataset.attributionText;
            link.href = root.dataset.attributionUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            map.attributionControl.addAttribution(link.outerHTML);
            map.on('click', (event) => { select(event.latlng); announce(`Selected ${subject}. Check the point and press ${confirmLabel}.`); });
            showMarker();
            load.textContent = site ? 'Site map loaded' : 'Pickup map loaded';
            load.disabled = true;
            announce('Map ready. Its starting centre is a view only; no pickup point is chosen automatically.');
        }).catch(() => { loading = null; fallback(); });
        return loading;
    };
    root.querySelector('[data-map-controls]').hidden = false;
    // Coordinate validity and confirmation are checked by the server, including without JS.
    latitude.required = false;
    longitude.required = false;
    if (!site && !latitude.hasAttribute('aria-invalid') && !longitude.hasAttribute('aria-invalid')) manual.open = false;
    for (const input of [latitude, longitude]) input.addEventListener('input', () => {
        point = readPoint(latitude.value, longitude.value);
        invalidate();
        showMarker();
    });
    for (const input of [latitude, longitude]) input.addEventListener('change', () => {
        if (map && point) map.panTo(point, { animate: false });
    });
    area?.addEventListener('change', invalidate);
    load.addEventListener('click', ensureMap);
    if (root.dataset.mapEnabled !== 'true') { load.disabled = true; fallback(); }
    locate.addEventListener('click', () => requestLocation(browser, (next, accuracy) => {
        select(next);
        accuracyMessage.textContent = `Reported browser accuracy: approximately ±${Math.ceil(accuracy)} metres. ${site ? 'Verify the actual facility; this locates your device.' : 'This is not an exact pickup guarantee.'}`;
        ensureMap().then(() => { if (map) map.setView(point, 16); });
    }, announce, () => {
        // One explicit request per page load; denial never starts a permission loop.
        locate.disabled = true;
        announce('Waiting for browser location permission… You can select the point manually instead.');
    }, site));
    root.querySelector('[data-use-centre]').addEventListener('click', () => {
        if (map) { select(map.getCenter()); announce(`Map centre selected. Check the point and press ${confirmLabel}.`); }
    });
    const directions = { north: [0, -100], south: [0, 100], east: [100, 0], west: [-100, 0] };
    root.querySelectorAll('[data-pan]').forEach((button) => button.addEventListener('click', () => {
        if (map) map.panBy(directions[button.dataset.pan], { animate: false });
    }));
    // Restored points remain private server draft data; loading tiles is always optional.
    if (point) status.textContent = root.dataset.confirmed === 'true' ? `✓ Selected ${subject} confirmed.` : `ⓘ Selected ${subject} needs confirmation.`;
}
