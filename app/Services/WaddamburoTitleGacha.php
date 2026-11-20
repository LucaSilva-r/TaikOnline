<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerCosmetic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Donder Hiroba's title parts gacha (称号パーツガシャ, lab research/wiki/katsu-medals.md): a banner per gacha Green's
 * predecessors ran (green_title_parts.json, lab research/ghidra/scripts/green_title_parts.py), any of them at any time,
 * 20 Katsu Medals a pull, one part per pull, drawn from the banner's parts including those already owned (no refund).
 */
class WaddamburoTitleGacha
{
    public const PRICE = 20;

    /** @return array{banners: list<array{key: string, name: string, start: string, version: string, parts: list<string>}>, b: list<string>} */
    public function data(): array
    {
        static $data = null;
        if ($data !== null) {
            return $data;
        }
        $path = config('taiko_green.data_path').DIRECTORY_SEPARATOR.'green'.DIRECTORY_SEPARATOR.'green_title_parts.json';

        return $data = File::exists($path) ? json_decode(File::get($path), true) : ['banners' => [], 'b' => []];
    }

    /** @return list<string> the parts the player drew, as "banner:index" */
    public function owned(Player $player): array
    {
        return PlayerCosmetic::query()->where('baid', $player->baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
            ->value('wdb_title_parts') ?? [];
    }

    /**
     * Pulls a banner $count times: the parts drawn, each with whether it is new, or null when the banner is unknown or
     * the Katsu Medals are short.
     *
     * @return list<array{key: string, text: string, new: bool}>|null
     */
    public function pull(Player $player, string $banner, int $count): ?array
    {
        $parts = collect($this->data()['banners'])->firstWhere('key', $banner)['parts'] ?? [];
        if ($parts === []) {
            return null;
        }

        return DB::transaction(function () use ($player, $banner, $count, $parts): ?array {
            Player::query()->lockForUpdate()->findOrFail($player->baid);
            if (app(WaddamburoKatsuShop::class)->wallet($player->baid) < self::PRICE * $count) {
                return null;
            }
            $row = PlayerCosmetic::resolve($player->baid, PlayerCosmetic::WADDAMBURO);
            $owned = $row->wdb_title_parts ?? [];
            $drawn = [];
            for ($i = 0; $i < $count; $i++) {
                $index = random_int(0, count($parts) - 1);
                $key = "{$banner}:{$index}";
                $drawn[] = ['key' => $key, 'text' => $parts[$index], 'new' => ! in_array($key, $owned, true)];
                $owned[] = $key;
            }
            $row->wdb_title_parts = array_values(array_unique($owned));
            $row->wdb_gacha_spent = (int) $row->wdb_gacha_spent + self::PRICE * $count;
            $row->save();

            return $drawn;
        });
    }
}
