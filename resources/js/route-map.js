import { readPoint } from './pickup-location.js';

export function routePoints(raw) {
    const values = JSON.parse(raw);
    if (!Array.isArray(values) || values.length < 2 || values.length > 52) throw new Error('Invalid route display');
    return values.map((value) => {
        if (!Array.isArray(value) || value.length !== 2) throw new Error('Invalid route display');
        const point = readPoint(...value);
        if (!point) throw new Error('Invalid route display');
        return [point.lat, point.lng];
    });
}

export function enhanceRouteMap(root, loadLeaflet = () => Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]).then(([leaflet]) => leaflet.default)) {
    const button = root.querySelector('button');
    const panel = root.querySelector('[data-route-map-panel]');
    const status = root.querySelector('[role="status"]');
    button.hidden = false;
    if (root.dataset.mapEnabled !== 'true') {
        button.disabled = true;
        status.textContent = 'Maps are disabled. The complete ordered list remains available.';
        return;
    }
    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const points = routePoints(root.dataset.points);
            const L = await loadLeaflet();
            panel.hidden = false;
            const map = L.map(panel, { scrollWheelZoom: false, touchZoom: false, dragging: !L.Browser.mobile, keyboard: false, attributionControl: true });
            const tiles = L.tileLayer(root.dataset.tileUrl, { maxZoom: 19, referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
            tiles.on('tileerror', () => { status.textContent = 'Background tiles are unavailable. Use the ordered list; geometric lines are not road navigation.'; });
            const link = document.createElement('a');
            link.href = root.dataset.attributionUrl;
            link.textContent = root.dataset.attributionText;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            map.attributionControl.addAttribution(link.outerHTML);
            const line = L.polyline(points, { color: '#206346', weight: 4 }).addTo(map);
            points.forEach((point) => L.circleMarker(point, { radius: 5 }).addTo(map));
            map.fitBounds(line.getBounds().pad(0.1), { maxZoom: 16 });
            status.textContent = 'Simulated straight-line legs shown. Use the ordered list for stop order; this is not road navigation.';
        } catch {
            panel.hidden = true;
            status.textContent = 'Map unavailable. The complete ordered list remains available; no route was changed.';
        }
    });
}
