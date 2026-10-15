<?php

return [
        'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/')],

    'allowed_origins_patterns' => [],

        'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Tenant-Id'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,
];