<?php

return [
    'enabled' => env('PICKUP_MAP_ENABLED', true),
    'tile_url' => env('PICKUP_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'attribution_text' => env('PICKUP_MAP_ATTRIBUTION_TEXT', '© OpenStreetMap contributors'),
    'attribution_url' => env('PICKUP_MAP_ATTRIBUTION_URL', 'https://www.openstreetmap.org/copyright'),
];
