<?php

namespace App\Models;

use App\Observers\ExtraStatsObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ExtraStatsObserver::class)]
#[Fillable([
    'baid', 'extra_chart_id', 'is_shin', 'best_score', 'best_score_rank',
    'best_play_result', 'best_crown',
])]
class ExtraChartBest extends Model
{
    protected function casts(): array
    {
        return ['is_shin' => 'boolean'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }

    public function chart(): BelongsTo
    {
        return $this->belongsTo(ExtraChart::class, 'extra_chart_id');
    }
}
