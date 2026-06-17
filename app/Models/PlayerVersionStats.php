<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'game_version',
    'user_id',
    'total_score',
    'ranked_song_count',
    'played_song_count',
    'crown_none',
    'crown_clear',
    'crown_gold',
    'crown_dondaful',
    'good_total',
    'ok_total',
    'miss_total',
    'precision',
])]
class PlayerVersionStats extends Model
{
    protected function casts(): array
    {
        return [
            'game_version' => 'string',
            'precision' => 'float',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
