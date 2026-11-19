<?php

namespace App\GameProtocol\Support;

use App\Enums\TaikoGameVersion;
use Carbon\CarbonImmutable;
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

        // 0 = follow the calendar: the season whose start (month/day) came last.
        if ($this->activeSeasonId === 0 && $this->seasons !== []) {
            $this->activeSeasonId = collect($this->seasons)
                ->sortByDesc(fn (array $season) => $this->lastStart($season['start_datetime']))
                ->keys()->first();
        }

        if (! isset($this->seasons[$this->activeSeasonId])) {
            $this->isEnabled = false;

            return;
        }

        $this->seasons[$this->activeSeasonId] = $this->inCurrentCycle($this->seasons[$this->activeSeasonId]);
    }

    /**
     * The data keeps Green's real dates (2019-20). The cabinet gives every bonus (daily, weekly,
     * monthly) when the card's last play is outside [start, end), so the active season is moved
     * into the current cycle: same month/day, the latest start before now, the first end after now.
     */
    private function inCurrentCycle(array $season): array
    {
        $start = $this->lastStart($season['start_datetime']);
        $end = $this->project($season['end_datetime'], now()->year - 1);
        while ($end <= now() || $end <= $start) {
            $end = $end->addYear();
        }

        return [
            ...$season,
            // The cabinet caches the shop (updates/<ver>/rewardshopdata.bin) and only downloads it
            // again when initialdatacheck's verup_no changes: one per cycle.
            'verup_no' => (int) $start->format('Ymd'),
            'start_datetime' => $start->format('YmdHis'),
            'end_datetime' => $end->format('YmdHis'),
        ];
    }

    private function lastStart(string $datetime): CarbonImmutable
    {
        $start = $this->project($datetime, now()->year);

        return $start > now() ? $start->subYear() : $start;
    }

    /** A YmdHis date moved to another year (Feb 29 falls to Mar 1). */
    private function project(string $datetime, int $year): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('YmdHis', $year.substr($datetime, 4));
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
