<?php

return [
    // No paid provider or model is selected by default. Secrets are server-only.
    'driver' => env('YUTIV_TRANSLATION_DRIVER', 'disabled'),
    'endpoint' => env('YUTIV_TRANSLATION_ENDPOINT'),
    'model' => env('YUTIV_TRANSLATION_MODEL'),
    'key' => env('YUTIV_TRANSLATION_API_KEY'),
    'queue' => 'ecommerce-translation',
];
