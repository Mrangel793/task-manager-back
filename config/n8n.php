<?php

return [

    /*
    |--------------------------------------------------------------------------
    | n8n Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file manages settings for n8n webhook integration.
    | Configure the base URL and authentication settings for your n8n instance.
    |
    */

    'base_url' => env('N8N_BASE_URL', 'http://localhost:5678'),

    'webhook_url' => env('N8N_WEBHOOK_URL', env('N8N_BASE_URL', 'http://localhost:5678') . '/webhook'),

    'webhook_test_url' => env('N8N_WEBHOOK_TEST_URL', env('N8N_BASE_URL', 'http://localhost:5678') . '/webhook-test'),

    'api_key' => env('N8N_API_KEY', null),

    'webhook_secret' => env('N8N_WEBHOOK_SECRET', null),

    'timeout' => env('N8N_TIMEOUT', 30),

    'verify_ssl' => env('N8N_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Webhook Events
    |--------------------------------------------------------------------------
    |
    | Configure which events should trigger n8n webhooks.
    |
    */

    'events' => [
        'task.created' => env('N8N_TASK_CREATED_ENABLED', true),
        'task.updated' => env('N8N_TASK_UPDATED_ENABLED', true),
        'task.deleted' => env('N8N_TASK_DELETED_ENABLED', true),
        'task.completed' => env('N8N_TASK_COMPLETED_ENABLED', true),
    ],

];
