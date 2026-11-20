<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    | `driver` is the provider a SACCO is given when it is set up with
    | `payments:configure`. Each SACCO keeps its own provider and credentials in
    | sacco_payment_settings. Supported: flutterwave, fake.
    */
    'driver' => env('PAYMENTS_DRIVER', 'flutterwave'),

    // sandbox | live. Live is refused unless allow_live is switched on on purpose.
    'environment' => env('PAYMENTS_ENVIRONMENT', 'sandbox'),
    'allow_live' => (bool) env('PAYMENTS_ALLOW_LIVE', false),

    'currency' => 'UGX',

    'flutterwave' => [
        // Flutterwave v3 has one base URL; sandbox or live is decided by the keys.
        'base_url' => env('FLW_V3_BASE_URL', 'https://api.flutterwave.com/v3'),
        'timeout' => (int) env('FLW_V3_TIMEOUT', 20),
    ],

    // Minutes after which a payment that was never completed is marked expired.
    'expire_after_minutes' => (int) env('PAYMENTS_EXPIRE_AFTER_MINUTES', 120),

    // Reconciliation only re-checks payments at least this old (the webhook usually arrives first).
    'reconcile_after_minutes' => (int) env('PAYMENTS_RECONCILE_AFTER_MINUTES', 3),

];
