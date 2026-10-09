@props(['fields', 'confirmed' => false, 'purpose' => 'pickup'])
@php($site = $purpose === 'site')
<fieldset id="pickup_point" tabindex="-1" class="pickup-location" data-pickup-location
    data-location-kind="{{ $purpose }}" data-confirmed="{{ $confirmed ? 'true' : 'false' }}" data-map-enabled="{{ config('maps.enabled') ? 'true' : 'false' }}"
    data-tile-url="{{ config('maps.tile_url') }}" data-attribution-text="{{ config('maps.attribution_text') }}" data-attribution-url="{{ config('maps.attribution_url') }}"
    @if($errors->has($site ? 'site_confirmation' : 'pickup_point')) aria-invalid="true" aria-describedby="pickup_point-error pickup-location-help" @else aria-describedby="pickup-location-help" @endif>
    <legend>{{ $site ? 'Choose the site entrance in Nairobi' : 'Choose your pickup point in Nairobi' }}</legend>
    <p id="pickup-location-help">{{ $site ? 'Check the pin marks the facility entrance. If you have surveyed or otherwise verified coordinates, enter them for a more precise location. Use my location locates your current device, which may not be at the facility.' : 'Select the entrance or meeting point, then confirm it. Westlands, Kilimani and Kasarani are geographical examples, not approved service locations.' }} A pin does not mean approved service or a booked pickup. The study service area is synthetic.</p>
    <p class="hint">The optional map loads external tiles from the configured provider (OpenStreetMap by default). It receives your IP address, browser information, site origin and approximate map area, but not your address text, name or contact. <a href="{{ route('privacy.notice') }}">Read the privacy notice</a>. You can use {{ $site ? 'manual latitude / longitude entry' : 'Advanced location entry' }} instead.</p>
    <div class="map-enhancement" data-map-controls hidden>
        <div class="actions"><button type="button" class="secondary" data-load-map>{{ $site ? 'Load site map' : 'Load pickup map' }}</button><button type="button" class="secondary" data-geolocate>Use my location</button></div>
        <div data-map-panel hidden>
            <p class="hint" id="pickup-map-help">Click or tap to place the pin; drag it to adjust. With the map focused, use arrow keys to move the view and +/− to zoom, then choose Use map centre. Tab leaves the map. On mobile, use the direction and zoom controls; swiping the map scrolls the page.</p>
            <div class="pickup-map" data-map role="region" aria-label="{{ $site ? 'Site entrance map' : 'Pickup map' }}" aria-describedby="pickup-map-help"></div>
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
    <p data-point-status class="hint">{{ $confirmed ? '✓ Selected '.($site ? 'site entrance' : 'pickup point').' confirmed.' : 'ⓘ No confirmed '.($site ? 'site entrance' : 'pickup point').' yet.' }}</p>
    @if($site)<input type="hidden" name="site_confirmation" value="{{ $fields['site_confirmation'] ?? '' }}" data-site-confirmation>@else<input type="hidden" name="pickup_changed" value="1" data-pickup-changed disabled>@endif
    <details data-manual-location open>
        <summary>{{ $site ? 'Manual latitude / longitude entry' : 'Advanced location entry' }}</summary>
        <p class="hint">For unavailable maps or JavaScript, enter decimal coordinates (up to 6 decimal places), then press {{ $site ? 'Confirm site entrance' : 'Confirm pickup point' }}. No location is chosen automatically.</p>
        <div class="form-grid"><x-field name="latitude" label="Latitude" :value="is_scalar($fields['latitude'] ?? null) ? (string) $fields['latitude'] : ''" autocomplete="off" hint="From −90 to 90, decimal degrees." /><x-field name="longitude" label="Longitude" :value="is_scalar($fields['longitude'] ?? null) ? (string) $fields['longitude'] : ''" autocomplete="off" hint="From −180 to 180, decimal degrees." /></div>
    </details>
    <noscript><p class="hint">JavaScript is unavailable. Open {{ $site ? 'manual latitude / longitude entry' : 'Advanced location entry' }} to enter and confirm a point.</p></noscript>
    <button class="secondary" type="submit" name="intent" value="{{ $site ? 'confirm_site' : 'confirm_pickup' }}" formnovalidate data-confirm-point>{{ $site ? 'Confirm site entrance' : 'Confirm pickup point' }}</button>
    @error($site ? 'site_confirmation' : 'pickup_point')<p class="field-error" id="pickup_point-error">{{ $message }}</p>@enderror
</fieldset>
