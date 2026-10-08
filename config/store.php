<?php
return [
    'mode' => getenv('STONEFELLOW_STORE_MODE') ?: 'test', // test | production
    'currency' => 'USD',
    'shipping_flat_cents' => 800,
    'products' => [
        'custom_vinyl' => [
            'label' => 'Custom 12-inch vinyl',
            'price_cents' => 4900,
            'format' => 'vinyl',
        ],
        'custom_cassette' => [
            'label' => 'Custom cassette',
            'price_cents' => 2400,
            'format' => 'cassette',
        ],
    ],
    'limits' => [
        'vinyl' => 1200,
        'cassette' => 2400,
    ],
    // Production deliberately has no built-in payment secret. Add an adapter
    // and set STONEFELLOW_STORE_MODE=production before accepting real orders.
    'payment_provider' => getenv('STONEFELLOW_PAYMENT_PROVIDER') ?: 'test',
    // Cash is a simulation-only method for testing the complete purchase flow.
    // It is never accepted when STONEFELLOW_STORE_MODE=production.
    'simulated_cash_enabled' => true,
    'pod_provider' => getenv('STONEFELLOW_POD_PROVIDER') ?: 'file_handoff',
];
