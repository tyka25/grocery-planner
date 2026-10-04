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

    /*
     * Canonical-item matching (App\Services\Matching). Suggestions at or above
     * suggest_threshold are written as match_status = 'auto' and wait for a
     * human yes/no; nothing is ever marked 'confirmed' automatically.
     *
     * noise_phrases: removed from descriptions before comparing (store brands
     *                and packaging words that say nothing about *what* the
     *                item is). Matched case-insensitively on word boundaries.
     *                Multi-word phrases are listed so e.g. "fresh thyme" (the
     *                store) can go without dropping "thyme" (the herb).
     * stopwords:     single tokens dropped after tokenizing.
     */
    /*
     * Store-assignment planner (App\Services\Planner). Every item goes to its
     * usual store; items only move when that store is out of stock or an
     * order misses its minimum (see Planner for the repair rules).
     *
     * pickup_trip_cost:     what adding a pickup-only store (Fareway) to a
     *                       plan "costs", in dollars, when weighed against a
     *                       delivery fee. Household's call, not a measured fee.
     * assumed_delivery_fee: used when a store's delivery_fee column is empty.
     *                       Real per-store fees are still unverified (see
     *                       CLAUDE.md), so this is a placeholder estimate.
     * snapshot_max_age_hours: availability snapshots older than this are
     *                       ignored (stock treated as unknown).
     */
    'planner' => [
        'pickup_trip_cost' => 10.00,
        'assumed_delivery_fee' => 3.99,
        'snapshot_max_age_hours' => 48,
    ],

    'matching' => [
        'suggest_threshold' => 0.6,
        // In the "new item" dialog, look-alike products at or above this are
        // pre-ticked; ones between suggest_threshold and this are listed
        // unticked. Kept high because a pre-ticked wrong product gets
        // confirmed by a single click (seen on real data: "Organic Whole
        // Milk Plain Yogurt" at 0.67 against a whole-milk item).
        'preselect_threshold' => 0.8,
        'noise_phrases' => [
            'kirkland signature', 'simply nature', 'fresh thyme market', 'fresh thyme farmers market',
            'fresh thyme', "member's mark", 'members mark', 'good & gather', 'great value', 'hy-vee', 'hyvee',
            'short cuts', 'aldi', 'costco', 'fareway', 'publix', 'target',
        ],
        'stopwords' => [
            'a', 'an', 'and', 'the', 'of', 'with', 'in', 'for', 'or', 'to',
            'fresh', 'package', 'bag', 'bagged', 'each', 'style', 'premium', 'value', 'pack',
        ],
    ],
];
