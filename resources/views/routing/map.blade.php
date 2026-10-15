<div data-route-map data-map-enabled="{{ config('maps.enabled') ? 'true' : 'false' }}" data-tile-url="{{ config('maps.tile_url') }}" data-attribution-text="{{ config('maps.attribution_text') }}" data-attribution-url="{{ config('maps.attribution_url') }}" data-points="{{ json_encode($mapPoints,JSON_THROW_ON_ERROR) }}">
    <p>Optional external background map: loading it contacts the configured tile provider with tile requests and your IP. Names, contacts and address text are not sent. <a href="{{ route('privacy.notice') }}">Privacy notice</a>. Lines show simulated straight-line legs, not roads.</p>
    <button type="button" class="secondary" hidden>Show simulated map</button>
    <p role="status">The complete ordered list works without a map or JavaScript.</p>
    <div class="pickup-map" data-route-map-panel hidden role="region" aria-label="Simulated straight-line route map; use the adjacent ordered list"></div>
</div>
