<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'chassis_id',
    'shop_id',
    'played_at',
    'is_right',
    'is_two_players',
    'song_no',
    'level',
    'stage_mode',
    'play_result',
    'score',
    'score_rank',
    'good_count',
    'ok_count',
    'miss_count',
    'drumroll_count',
    'combo_count',
    'hit_count',
    'music_category',
    'selected_folder_id',
    'raw_stage',
])]
class SongPlayResult extends Model
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
}
