<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agente autónomo de WhatsApp
    |--------------------------------------------------------------------------
    |
    | hmac_secret: secreto compartido con el servicio del agente. Vacío = todas
    | las rutas /api/agent/v1/* responden 401 (falla cerrado).
    | readonly_database: BD separada donde viven las vistas de solo lectura
    | (ver docs/agent-readonly.md y el comando agent:create-views).
    |
    */

    'hmac_secret' => env('AGENT_HMAC_SECRET', ''),

    'hmac_max_skew_seconds' => 300,

    'nonce_ttl_seconds' => 600,

    'token_ttl_minutes' => 15,

    'link_code_ttl_minutes' => 10,

    'link_max_failed_attempts' => 5,

    'link_global_max_failures' => 50,

    'readonly_database' => env('AGENT_RO_DATABASE'),

];
