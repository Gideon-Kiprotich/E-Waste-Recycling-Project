@props(['fields', 'confirmed' => false])
<fieldset id="pickup_point" tabindex="-1" class="pickup-location" data-pickup-location
    data-confirmed="{{ $confirmed ? 'true' : 'false' }}" data-map-enabled="{{ config('maps.enabled') ? 'true' : 'false' }}"
    data-tile-url="{{ config('maps.tile_url') }}" data-attribution-text="{{ config('maps.attribution_text') }}" data-attribution-url="{{ config('maps.attribution_url') }}"
    @if($errors->has('pickup_point')) aria-invalid="true" aria-describedby="pickup_point-error pickup-location-help" @else aria-describedby="pickup-location-help" @endif>
    <legend>Choose your pickup point</legend>
    <p id="pickup-location-help">Select the entrance or meeting point, then confirm it. A pin does not mean approved service or a booked pickup. The study service area is synthetic.</p>
    <p class="hint">The optional map loads external tiles from the configured provider (OpenStreetMap by default). It receives your IP address, browser information, site origin and approximate map area, but not your address text, name or contact. <a href="{{ route('privacy.notice') }}">Read the privacy notice</a>. You can use Advanced location entry instead.</p>
    <div class="map-enhancement" data-map-controls hidden>
        <div class="actions"><button type="button" class="secondary" data-load-map>Load pickup map</button><button type="button" class="secondary" data-geolocate>Use my location</button></div>
        <div data-map-panel hidden>
            <p class="hint" id="pickup-map-help">Click or tap to place the pin; drag it to adjust. With the map focused, use arrow keys to move the view and +/− to zoom, then choose Use map centre. Tab leaves the map. On mobile, use the direction and zoom controls; swiping the map scrolls the page.</p>
            <div class="pickup-map" data-map role="region" aria-label="Pickup map" aria-describedby="pickup-map-help"></div>
            <div class="map-position-controls" aria-label="Position map centre">
                <button type="button" class="secondary" data-pan="north" aria-label="Move map centre north">↑ North</button>
                <button type="button" class="secondary" data-pan="west" aria-label="Move map centre west">← West</button>
                <button type="button" class="secondary" data-pan="east" aria-label="Move map centre east">East →</button>
                <button type="button" class="secondary" data-pan="south" aria-label="Move map centre south">↓ South</button>
                <button type="button" class="secondary" data-use-centre>Use map centre</button>
            </div>
            <p class="hint"><a href="https://www.openstreetmap.org/fixthemap" target="_blank" rel="noopener noreferrer">Report an OpenStreetMap issue</a>. Tiles are best-effort; the manual fallback remains available.</p>
        </div>
    </div>
    <p data-location-message role="status" class="hint" aria-live="polite"></p>
    <p data-location-accuracy class="hint"></p>
    <p data-point-status class="hint">{{ $confirmed ? '✓ Selected pickup point confirmed.' : 'ⓘ No confirmed pickup point yet.' }}</p>
    <input type="hidden" name="pickup_changed" value="1" data-pickup-changed disabled>
    <details data-manual-location open>
        <summary>Advanced location entry</summary>
        <p class="hint">For unavailable maps or JavaScript, enter decimal coordinates (up to 6 decimal places), then press Confirm pickup point. No location is chosen automatically.</p>
        <div class="form-grid"><x-field name="latitude" label="Latitude" :value="is_scalar($fields['latitude'] ?? null) ? (string) $fields['latitude'] : ''" autocomplete="off" hint="From −90 to 90, decimal degrees." /><x-field name="longitude" label="Longitude" :value="is_scalar($fields['longitude'] ?? null) ? (string) $fields['longitude'] : ''" autocomplete="off" hint="From −180 to 180, decimal degrees." /></div>
    </details>
    <noscript><p class="hint">JavaScript is unavailable. Open Advanced location entry to enter and confirm a point.</p></noscript>
    <button class="secondary" type="submit" name="intent" value="confirm_pickup" formnovalidate data-confirm-point>Confirm pickup point</button>
    @error('pickup_point')<p class="field-error" id="pickup_point-error">{{ $message }}</p>@enderror
</fieldset>
