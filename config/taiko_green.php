<?php

use App\Enums\TaikoGameVersion;

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
    'catalog_version' => env('TAIKO_GREEN_CATALOG_VERSION', TaikoGameVersion::Green->value),
    'route_catalog_versions' => [
        'v01r00_tw' => TaikoGameVersion::Red->value,
        'v10' => TaikoGameVersion::Blue->value,
        'v11' => TaikoGameVersion::Green->value,
    ],
    'startup_movie_ids' => [
        'v11' => 154,
    ],
    'traffic_log_enabled' => (bool) env('TAIKO_GREEN_TRAFFIC_LOG_ENABLED', true),
    'zucchini_api_token_hashes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TAIKO_ZUCCHINI_API_TOKEN_HASHES', '')),
    ))),
    'nbgic_profile_records' => env('TAIKO_GREEN_NBGIC_PROFILE_RECORDS'),
    'nbgic_generation_profiles' => array_map(
        'intval',
        array_values(array_filter(
            array_map('trim', explode(',', (string) env('TAIKO_GREEN_NBGIC_GENERATION_PROFILES', '7'))),
            fn (string $profile): bool => $profile !== '',
        )),
    ),

    'enable_shop' => env('TAIKO_GREEN_ENABLE_SHOP', true),
    'active_shop_season_id' => (int) env('TAIKO_GREEN_ACTIVE_SHOP_SEASON_ID', 4),

    'mucha_force_update' => (bool) env('TAIKO_GREEN_MUCHA_FORCE_UPDATE', false),
    'mucha_forced_target_ver' => env('TAIKO_GREEN_MUCHA_TARGET_VER', 'S1110JPN99.99'),
    'mucha_chunk_path' => env('TAIKO_GREEN_MUCHA_CHUNK_PATH', storage_path('app/mucha/chunk.img')),
];
