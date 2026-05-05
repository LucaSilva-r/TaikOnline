<?php

return [
    'game_url' => env('TAIKO_GREEN_GAME_URL', 'vsapi.taiko-p.jp'),
    'mucha_url' => env('TAIKO_GREEN_MUCHA_URL', 'https://v402-front.mucha-prd.nbgi-amnet.jp:10122'),
    'place_id' => env('TAIKO_GREEN_PLACE_ID', 'JPN0123'),
    'shop_name' => env('TAIKO_GREEN_SHOP_NAME', 'NAMCO'),
    'country' => env('TAIKO_GREEN_COUNTRY', 'JPN'),
    'region' => (int) env('TAIKO_GREEN_REGION', 1),
    'data_path' => env('TAIKO_GREEN_DATA_PATH', storage_path('app/game-data')),
];
