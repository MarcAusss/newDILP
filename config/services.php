<?php

return [
    'miro' => [
        'client_id' => env('MIRO_CLIENT_ID'),
        'client_secret' => env('MIRO_CLIENT_SECRET'),
        'redirect_uri' => env('MIRO_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/miro/callback'),
        'authorize_url' => 'https://miro.com/oauth/authorize',
        'token_url' => 'https://api.miro.com/v1/oauth/token',
        'api_url' => 'https://api.miro.com/v2',
    ],
];
