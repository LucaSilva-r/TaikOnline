<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\WdbMedalCredit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Green's Katsu Medal shop (Donder Hiroba's かつメダル ごほうびショップ, lab research/wiki/katsu-medals.md): earlier
 * versions' goods, sold on the website only, any of them at any time, for Katsu Medals (100 a credit, never reset).
 * Its goods (green_katsu_shop.json, lab research/ghidra/scripts/green_katsu_shop.py) stay out of the cabinet's daily pool.
 */
class WaddamburoKatsuShop
{
    /** The season_id its purchases carry in player_shop_items (the cabinet's are Green's seasons 1-4). */
    public const SEASON = 99;

    public const MEDALS_PER_CREDIT = 100;

    /** ponytail: 気焔万丈神楽 cost 1500 in Green; no costume price is known. Tune after player feedback. */
    public const PRICES = [1 => 1500, 3 => 500, 4 => 500, 5 => 500];

    /**
     * Every good: [type, id, name] (songs by uniqueId); empty when the data file is missing.
     *
     * @return list<array{int, int, string}>
     */
    public function items(): array
    {
        static $items = null;
        if ($items !== null) {
            return $items;
        }
        $path = config('taiko_green.data_path').DIRECTORY_SEPARATOR.'green'.DIRECTORY_SEPARATOR.'green_katsu_shop.json';

        return $items = array_map(fn (array $item): array => [(int) $item[0], (int) $item[1], (string) ($item[2] ?? '')],
            File::exists($path) ? (json_decode(File::get($path), true)['items'] ?? []) : []);
    }

    public function wallet(int $baid): int
    {
        return (int) WdbMedalCredit::query()->where('baid', $baid)->sum('katsu')
            - (int) PlayerShopItem::query()->where('baid', $baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
                ->where('season_id', self::SEASON)->sum('item_price');
    }

    /** @return array<string, true> "type:id" of every good the player has (bought here or on a cabinet, or a Green unlock) */
    public function owned(Player $player): array
    {
        $owned = [];
        foreach (PlayerShopItem::query()->where('baid', $player->baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
            ->get(['item_type', 'item_id']) as $item) {
            $owned[$item->item_type.':'.$item->item_id] = true;
        }
        foreach ($player->unlocked_song_numbers ?? [] as $song) {
            $owned['1:'.(int) $song] = true;
        }

        return $owned;
    }

    /** Buys a good: 'ok', 'owned', 'short' (not enough medals) or 'unknown' (not sold here). */
    public function buy(Player $player, int $type, int $id): string
    {
        if (! collect($this->items())->contains(fn (array $item): bool => $item[0] === $type && $item[1] === $id)) {
            return 'unknown';
        }

        return DB::transaction(function () use ($player, $type, $id): string {
            Player::query()->lockForUpdate()->findOrFail($player->baid);
            if (isset($this->owned($player)["{$type}:{$id}"])) {
                return 'owned';
            }
            $price = self::PRICES[$type];
            if ($this->wallet($player->baid) < $price) {
                return 'short';
            }
            PlayerShopItem::query()->create([
                'baid' => $player->baid,
                'game_version' => PlayerCosmetic::WADDAMBURO,
                'season_id' => self::SEASON,
                'item_type' => $type,
                'item_id' => $id,
                'item_no' => 0,
                'item_price' => $price,
            ]);

            return 'ok';
        });
    }
}
