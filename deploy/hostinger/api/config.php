<?php
declare(strict_types=1);

return [
    /*
     * Optional full-compatibility upstream backend.
     * Example: 'https://your-node-backend.example.com'
     * When set, all /api/* calls are forwarded to `${backend_base_url}/api/*`.
     */
    'backend_base_url' => getenv('GEV_API_BACKEND_BASE_URL') ?: '',

    /*
     * Default timeout for outbound proxy calls (seconds).
     */
    'timeout_seconds' => 20,
];
