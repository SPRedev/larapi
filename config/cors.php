<?php

return [

    'paths' => ['api/*'], // This is correct for your setup

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // For local development, allowing all origins is easiest.
        '*', 
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];