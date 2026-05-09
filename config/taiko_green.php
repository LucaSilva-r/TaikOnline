<?php

return [
    'game_url' => env('TAIKO_GREEN_GAME_URL', 'vsapi.taiko-p.jp'),
    'allnet_host' => env('TAIKO_GREEN_ALLNET_HOST', env('TAIKO_GREEN_DNS_ADDRESS', '127.0.0.1')),
    'mucha_url' => env('TAIKO_GREEN_MUCHA_URL', 'https://v402-front.mucha-prd.nbgi-amnet.jp:10122'),
    'mucha_game_url' => env('TAIKO_GREEN_MUCHA_GAME_URL', 'https://127.0.0.1:54430'),
    'place_id' => env('TAIKO_GREEN_PLACE_ID', 'JPN0123'),
    'shop_name' => env('TAIKO_GREEN_SHOP_NAME', 'NAMCO'),
    'country' => env('TAIKO_GREEN_COUNTRY', 'JPN'),
    'region' => (int) env('TAIKO_GREEN_REGION', 1),
    'data_path' => env('TAIKO_GREEN_DATA_PATH', storage_path('app/game-data')),
    'traffic_log_enabled' => (bool) env('TAIKO_GREEN_TRAFFIC_LOG_ENABLED', true),

    'mucha_force_update' => (bool) env('TAIKO_GREEN_MUCHA_FORCE_UPDATE', false),
    'mucha_forced_target_ver' => env('TAIKO_GREEN_MUCHA_TARGET_VER', 'S1110JPN99.99'),
    'mucha_chunk_path' => env('TAIKO_GREEN_MUCHA_CHUNK_PATH', storage_path('app/mucha/chunk.img')),
];
