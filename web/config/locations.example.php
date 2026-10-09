<?php

// Copy to locations.local.php (gitignored) and use your own addresses.
// See location_resolver in grocery_planner.php for what each field means.
// The tests use this file as-is, against tests/Fixtures.
return [
    '123 Main' => ['label' => 'Home (Springfield)', 'kind' => 'home', 'fulfillment' => 'delivery', 'store_slug_hint' => null],
    'Market Road' => ['label' => 'Fareway pickup (Riverside)', 'kind' => 'pickup_site', 'fulfillment' => 'pickup', 'store_slug_hint' => 'fareway-meat-grocery'],
    'Seaside' => ['label' => 'Travel (FL)', 'kind' => 'travel', 'fulfillment' => 'delivery', 'store_slug_hint' => null],
    'Bayview' => ['label' => 'Travel (FL)', 'kind' => 'travel', 'fulfillment' => 'delivery', 'store_slug_hint' => null],
];
