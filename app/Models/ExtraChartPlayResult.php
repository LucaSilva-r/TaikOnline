<?php

namespace App\Models;

use App\Observers\ExtraStatsObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ExtraStatsObserver::class)]
#[Fillable([
    'baid', 'extra_chart_id', 'origin_game_version', 'chassis_id', 'shop_id',
    'session_hash', 'played_at', 'stage_index', 'is_right', 'is_two_players',
    'runtime_song_no', 'level', 'stage_mode', 'play_result', 'score',
    'score_rank', 'good_count', 'ok_count', 'miss_count', 'drumroll_count',
    'combo_count', 'hit_count', 'music_category', 'selected_folder_id',
    'raw_stage',
])]
class ExtraChartPlayResult extends Model
{
    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
            'is_right' => 'boolean',
            'is_two_players' => 'boolean',
            'raw_stage' => 'array',
        ];
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
