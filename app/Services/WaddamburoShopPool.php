<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * The goods Green gives no way to earn (Katsu Medal shop, older versions, expired campaigns; lab
 * research/ghidra/green_item_routes.tsv): kigurumi, heads, bodies and stock songs, in the fixed order of
 * green_shop_pool.json. Waddamburo's shop fills its free slots with them, a new set every week (Monday; Green's own
 * season goods stay as Green had them), so the whole pool comes round in about a season.
 */
class WaddamburoShopPool
{
    /** reward_shop's BOX_MAX: the most goods one visit shows. */
    public const SLOTS = 18;

    /**
     * Its lineup's areas: costumes in boxes 1-11, songs and tones in 12-17 (box 0 is the exit). Goods past an area's end
     * spill into the next one and the last goods get no box (a "ghost" song the cursor wraps onto).
     */
    public const COSTUME_SLOTS = 11;

    public const SONG_SLOTS = 6;

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
     * The week's share of the pool in the season's goods format, numbered after them.
     *
     * @param  list<array<string, mixed>>  $seasonItems
     * @return list<array<string, mixed>>
     */
    public function today(array $seasonItems, ?CarbonImmutable $day = null): array
    {
        // Weeks since a Monday: a new set each Monday.
        $days = intdiv((int) CarbonImmutable::parse('2025-12-29')->diffInDays(($day ?? CarbonImmutable::now())->startOfDay()), 7);
        $isSong = fn (int $type): bool => in_array($type, [1, 2], true);
        $seasonSongs = count(array_filter($seasonItems, fn (array $item): bool => $isSong((int) $item['item_type'])));
        $picked = [];
        // Each area fills up to its end with its own kind, the next ones each week.
        foreach ([[false, self::COSTUME_SLOTS - (count($seasonItems) - $seasonSongs)], [true, self::SONG_SLOTS - $seasonSongs]] as [$songs, $slots]) {
            $pool = array_values(array_filter($this->items(), fn (array $item): bool => $isSong($item[0]) === $songs));
            $slots = min($slots, count($pool));
            for ($i = 0; $i < $slots; $i++) {
                $picked[] = $pool[($days * $slots + $i) % count($pool)];
            }
        }
        $next = count($seasonItems) + 1;

        return array_map(fn (array $item, int $i): array => ['item_no' => $next + $i, 'item_type' => $item[0], 'item_id' => $item[1],
            'item_price' => self::PRICES[$item[0]] ?? 500], $picked, array_keys($picked));
    }
}
