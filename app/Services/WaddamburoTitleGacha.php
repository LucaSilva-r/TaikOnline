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

    /**
     * The player's parts for Donder Hiroba's title editor (part + connector + part): each owned part's key, text and
     * banner, in the gacha's order.
     *
     * @return list<array{key: string, text: string, banner: string}>
     */
    public function ownedParts(Player $player): array
    {
        $owned = array_flip($this->owned($player));
        $parts = [];
        foreach ($this->data()['banners'] as $banner) {
            foreach ($banner['parts'] as $index => $text) {
                if (isset($owned["{$banner['key']}:{$index}"])) {
                    $parts[] = ['key' => "{$banner['key']}:{$index}", 'text' => $text, 'banner' => $banner['key']];
                }
            }
        }

        return $parts;
    }

    /**
     * A title built from parts: an owned part (or none), a connector (index into the B parts; (なし) none, (スペース) a
     * space), an owned part (or none). Null when a part is not owned or the connector is unknown.
     */
    public function compose(Player $player, ?string $first, int $connector, ?string $second): ?string
    {
        $owned = array_column($this->ownedParts($player), 'text', 'key');
        $connectors = $this->data()['b'];
        if (($first !== null && ! isset($owned[$first])) || ($second !== null && ! isset($owned[$second])) || ! isset($connectors[$connector])) {
            return null;
        }
        $middle = match ($connectors[$connector]) {
            '(なし)' => '',
            '(スペース)' => '　',
            default => $connectors[$connector],
        };

        // A space connector between nothing is nothing (trim() is byte-wise: the ideographic space needs the regex).
        return (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', ($first === null ? '' : $owned[$first]).$middle.($second === null ? '' : $owned[$second]));
    }
}
