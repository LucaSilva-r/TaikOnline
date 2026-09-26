<?php

namespace App\Observers;

use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Services\PlayerRankAggregateService;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps player_version_stats in sync whenever a SongBest or SongPlayResult is
 * written or removed, so the Rankings/Board pages never aggregate on read.
 */
class PlayerVersionStatsObserver
{
    public function __construct(private readonly PlayerRankAggregateService $rankAggregates) {}

    public function saved(Model $model): void
    {
        $this->recompute($model);
    }

    public function deleted(Model $model): void
    {
        $this->recompute($model);
    }

    private function recompute(Model $model): void
    {
        $version = TaikoGameVersion::tryFrom((string) $model->getAttribute('game_version'));
        if (! $version instanceof TaikoGameVersion) {
            return;
        }

        $player = Player::query()->find($model->getAttribute('baid'));
        if (! $player instanceof Player) {
            return;
        }

        $this->rankAggregates->recompute($player, $version);
    }
}
