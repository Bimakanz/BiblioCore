<?php

return [
    /*
     |--------------------------------------------------------------------------
     | 9Router gateway
     |--------------------------------------------------------------------------
     |
     | OpenAI-compatible AI gateway. The local instance listens on 20128; a
     | deployed copy should point NINEROUTER_URL at the public tunnel/VPS.
     |
     */
    'url' => rtrim((string) env('NINEROUTER_URL', 'http://127.0.0.1:20128'), '/'),
    'key' => env('NINEROUTER_KEY'),

    /** Default chat model. Must exist in GET /v1/models. oc/*-free models are
     *  limited to OpenCode itself and answer 429 through the REST API. */
    'model' => env('NINEROUTER_MODEL', 'ag/gemini-4-hydrogen-high'),

    'timeout' => (int) env('NINEROUTER_TIMEOUT', 25),

    /*
     |--------------------------------------------------------------------------
     | Subtitle translation fallback
     |--------------------------------------------------------------------------
     |
     | The free Google endpoint is tried first. When it fails or returns a
     | misaligned chunk, 9Router can translate that chunk instead. Set
     | NINEROUTER_TRANSLATION=false to keep the old behaviour (no AI calls).
     |
     */
    'translation' => [
        'enabled' => env('NINEROUTER_TRANSLATION', true),
        'model' => env('NINEROUTER_TRANSLATION_MODEL', env('NINEROUTER_MODEL', 'ag/gemini-4-hydrogen-high')),
    ],
];
