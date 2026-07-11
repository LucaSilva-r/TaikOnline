<?php

namespace App\Observers;

use App\Models\Player;
use App\Services\ExtraRankAggregateService;
use Illuminate\Database\Eloquent\Model;

class ExtraStatsObserver
{
    public function __construct(private readonly ExtraRankAggregateService $aggregates) {}

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
        $player = Player::query()->find($model->getAttribute('baid'));
        if ($player instanceof Player) {
            $this->aggregates->recompute($player);
        }
    }
}
