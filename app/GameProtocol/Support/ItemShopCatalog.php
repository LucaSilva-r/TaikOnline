<?php

namespace App\GameProtocol\Support;

use App\Enums\TaikoGameVersion;
use Illuminate\Support\Facades\File;

class ItemShopCatalog
{
    public bool $isEnabled;

    public ?int $activeSeasonId;

    public array $seasons = []; // seasonId => seasonInfo

    public function __construct(TaikoGameVersion $version)
    {
        $this->isEnabled = (bool) config('taiko_green.enable_shop', true);
        $this->activeSeasonId = (int) config('taiko_green.active_shop_season_id', 4);

        if (! $this->isEnabled) {
            return;
        }

        $dirName = match ($version) {
            TaikoGameVersion::Blue => 'blue',
            TaikoGameVersion::Green => 'green',
            TaikoGameVersion::Yellow => 'yellow',
            default => null,
        };

        if ($dirName === null) {
            $this->isEnabled = false;

            return;
        }

        $fileName = "{$dirName}_item_shop_data.json";
        $path = config('taiko_green.data_path').DIRECTORY_SEPARATOR.$dirName.DIRECTORY_SEPARATOR.$fileName;

        if (! File::exists($path)) {
            $this->isEnabled = false;

            return;
        }

        $content = File::get($path);
        $data = json_decode($content, true);

        if (! isset($data['seasons']) || ! is_array($data['seasons'])) {
            $this->isEnabled = false;

            return;
        }

        foreach ($data['seasons'] as $season) {
            $seasonId = $season['season_id'] ?? null;
            if ($seasonId === null) {
                continue;
            }

            $items = [];
            if (isset($season['items']) && is_array($season['items'])) {
                $itemNo = 1;
                foreach ($season['items'] as $item) {
                    $itemTypeStr = $item['item_type'] ?? '';
                    $itemType = $this->parseItemType($itemTypeStr);

                    if ($itemType === null) {
                        continue;
                    }

                    $items[] = [
                        'item_no' => $itemNo++,
                        'item_type' => $itemType,
                        'item_id' => (int) ($item['item_id'] ?? 0),
                        'item_price' => (int) ($item['item_price'] ?? 0),
                    ];
                }
            }

            $this->seasons[$seasonId] = [
                'season_id' => $seasonId,
                'verup_no' => $season['verup_no'] ?? 1,
                'telop' => $season['telop'] ?? '',
                'start_datetime' => $season['start_datetime'] ?? '',
                'end_datetime' => $season['end_datetime'] ?? '',
                'afterstart_days' => $season['afterstart_days'] ?? 0,
                'beforeclose_days' => $season['beforeclose_days'] ?? 0,
                'items' => $items,
            ];
        }

        if (! isset($this->seasons[$this->activeSeasonId])) {
            $this->isEnabled = false;
        }
    }

    public function getActiveSeason(): ?array
    {
        if (! $this->isEnabled || $this->activeSeasonId === null) {
            return null;
        }

        return $this->seasons[$this->activeSeasonId] ?? null;
    }

    private function parseItemType(mixed $type): ?int
    {
        if (is_numeric($type)) {
            $val = (int) $type;

            return ($val >= 1 && $val <= 7) ? $val : null;
        }

        return match (strtolower((string) $type)) {
            'song' => 1,
            'tone' => 2,
            'kigurumi' => 3,
            'body' => 4,
            'head' => 5,
            'face' => 6,
            'puchi' => 7,
            default => null,
        };
    }
}
