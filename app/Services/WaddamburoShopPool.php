<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * The goods Green gives no way to earn (Katsu Medal shop, older versions, expired campaigns; lab
 * research/ghidra/green_item_routes.tsv): kigurumi, heads, bodies and stock songs, in the fixed order of
 * green_shop_pool.json. Waddamburo's shop fills its free slots with them, a new set every day, so the
 * whole pool comes round in about a month.
 */
class WaddamburoShopPool
{
    /** reward_shop's BOX_MAX: the most goods one visit shows. */
    public const SLOTS = 18;

    /** ponytail: Green's shop prices for every kind; tune after player feedback. */
    public const PRICES = [1 => 1500, 3 => 500, 4 => 500, 5 => 500];

    /**
     * Every pool item: [type, id] (songs by uniqueId); empty when the data file is missing.
     *
     * @return list<array{int, int}>
     */
    public function items(): array
    {
        static $items = null;
        if ($items !== null) {
            return $items;
        }
        $path = config('taiko_green.data_path').DIRECTORY_SEPARATOR.'green'.DIRECTORY_SEPARATOR.'green_shop_pool.json';

        return $items = array_map(fn (array $item): array => [(int) $item[0], (int) $item[1]],
            File::exists($path) ? (json_decode(File::get($path), true)['items'] ?? []) : []);
    }

    /**
     * The day's share of the pool in the season's goods format, numbered after them.
     *
     * @param  list<array<string, mixed>>  $seasonItems
     * @return list<array<string, mixed>>
     */
    public function today(array $seasonItems, ?CarbonImmutable $day = null): array
    {
        $pool = $this->items();
        $slots = min(self::SLOTS - count($seasonItems), count($pool));
        if ($slots <= 0) {
            return [];
        }
        $days = (int) CarbonImmutable::parse('2026-01-01')->diffInDays(($day ?? CarbonImmutable::now())->startOfDay());
        $first = ($days * $slots) % count($pool);
        $next = count($seasonItems) + 1;
        $goods = [];
        for ($i = 0; $i < $slots; $i++) {
            [$type, $id] = $pool[($first + $i) % count($pool)];
            $goods[] = ['item_no' => $next + $i, 'item_type' => $type, 'item_id' => $id, 'item_price' => self::PRICES[$type] ?? 500];
        }

        return $goods;
    }
}
