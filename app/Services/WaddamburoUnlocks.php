<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\ItemShopCatalog;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\Song;

/**
 * Waddamburo's "Only what I earned" mode: what a player has not earned yet. Only items with a known way to earn
 * them lock (today: the goods Green's reward shop sold, every season); everything else stays free. Owned: bought in
 * Waddamburo's shop, or unlocked on a real Green cabinet (the card's Green row). Off (the default): nothing locks.
 */
class WaddamburoUnlocks
{
    /** Item type (Green's shop numbering) → the cosmetics row's costume slot. */
    private const COSTUME_SLOTS = [3 => 1, 5 => 2, 4 => 3, 6 => 4, 7 => 5];

    /** Item type → the name the client and the website use. */
    public const TYPES = [1 => 'songs', 2 => 'tones', 3 => 'kigurumi', 4 => 'body', 5 => 'head', 6 => 'face', 7 => 'puchi'];

    public function earnedOnly(Player $player): bool
    {
        return (bool) $player->cosmetics()->where('game_version', PlayerCosmetic::WADDAMBURO)->value('wdb_earned_only');
    }

    /**
     * What stays hidden for the player, by kind: stock songs as musicids, everything else as item ids. Empty lists
     * when the mode is off.
     *
     * @return array<string, list<int|string>>
     */
    public function locked(Player $player): array
    {
        $locked = array_fill_keys(array_values(self::TYPES), []);
        if (! $this->earnedOnly($player)) {
            return $locked;
        }
        $owned = $this->owned($player);
        foreach ($this->sold() as [$type, $id]) {
            if (isset(self::TYPES[$type]) && ! in_array($id, $owned[$type] ?? [], true)) {
                $locked[self::TYPES[$type]][] = $id;
            }
        }
        // Songs are sold by uniqueId; the client knows them by musicid.
        $locked['songs'] = Song::query()->where('version', TaikoGameVersion::Green->value)
            ->whereIn('unique_id', $locked['songs'])->orderBy('music_id')->pluck('music_id')->all();

        return $locked;
    }

    /** Whether the player may wear or play this item (type as Green's shop numbers it). */
    public function allows(Player $player, int $type, int|string $id): bool
    {
        return ! in_array($id, $this->locked($player)[self::TYPES[$type]] ?? [], true);
    }

    /**
     * Every item Green's shop sold, any season.
     *
     * @return list<array{int, int}>
     */
    private function sold(): array
    {
        $items = [];
        foreach ((new ItemShopCatalog(TaikoGameVersion::Green, 0))->seasons as $season) {
            foreach ($season['items'] as $item) {
                $items[$item['item_type'].':'.$item['item_id']] = [(int) $item['item_type'], (int) $item['item_id']];
            }
        }

        return array_values($items);
    }

    /**
     * Owned item ids by type: Waddamburo purchases plus the card's real Green cabinet unlocks.
     *
     * @return array<int, list<int>>
     */
    private function owned(Player $player): array
    {
        $owned = [];
        foreach (PlayerShopItem::query()->where('baid', $player->baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
            ->get(['item_type', 'item_id']) as $item) {
            $owned[(int) $item->item_type][] = (int) $item->item_id;
        }
        $owned[1] = [...($owned[1] ?? []), ...array_map('intval', $player->unlocked_song_numbers ?? [])];
        $green = $player->cosmetics()->where('game_version', TaikoGameVersion::Green->value)->first();
        $owned[2] = [...($owned[2] ?? []), ...array_map('intval', $green?->unlocked_tones ?? [])];
        foreach (self::COSTUME_SLOTS as $type => $slot) {
            $owned[$type] = [...($owned[$type] ?? []), ...array_map('intval', ($green?->unlocked_costumes ?? [])[(string) $slot] ?? [])];
        }

        return $owned;
    }
}
