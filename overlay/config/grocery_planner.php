<?php

return [
    /*
     * Maps a substring of the CSV "Shipping Address" column to how that
     * address should be treated on import. Checked in order; first match
     * wins. An address matching nothing is reported as unresolved by the
     * import command rather than silently guessed at.
     *
     * kind:        home | pickup_site | travel
     * fulfillment: delivery | pickup
     * store_slug_hint: the Instacart retailer slug this address belongs to,
     *                   when it's a pickup site (used to sanity-check the
     *                   Store Name column on import).
     */
    'location_resolver' => [
        '123 Main' => ['label' => 'Home (Springfield)', 'kind' => 'home', 'fulfillment' => 'delivery', 'store_slug_hint' => null],
        'Market Road' => ['label' => 'Fareway pickup (Riverside)', 'kind' => 'pickup_site', 'fulfillment' => 'pickup', 'store_slug_hint' => 'fareway-meat-grocery'],
        'Seaside' => ['label' => 'Travel (FL)', 'kind' => 'travel', 'fulfillment' => 'delivery', 'store_slug_hint' => null],
        'Bayview' => ['label' => 'Travel (FL)', 'kind' => 'travel', 'fulfillment' => 'delivery', 'store_slug_hint' => null],
    ],
];
