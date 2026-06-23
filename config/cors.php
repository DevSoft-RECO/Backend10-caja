
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // ---------------------------------------------------------
        // 1. ENTORNO LOCAL (Desarrollo)
        // ---------------------------------------------------------
        'http://localhost:5190',      // Tu App Hija Local
        'http://127.0.0.1:5174',      // Variante IP

        // ---------------------------------------------------------
        // 2. ENTORNO PRODUCCIÓN (Google Cloud)
        // ---------------------------------------------------------
        'https://inventarioit.yamankutx.com.gt',       // Tu App Madre Real
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
