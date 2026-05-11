<?php

return [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/google/callback'),
    'frontend_success_url' => env('GOOGLE_FRONTEND_SUCCESS_URL', env('APP_URL') . '/settings?google=connected'),
    'frontend_error_url' => env('GOOGLE_FRONTEND_ERROR_URL', env('APP_URL') . '/settings?google=error'),
    'scopes' => [
        'https://www.googleapis.com/auth/calendar.events',
    ],
];
