<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['baid', 'game_version', 'song_no', 'level', 'best_score', 'best_score_rank', 'best_play_result', 'best_crown'])]
class SongBest extends Model
{
    protected function casts(): array
    {
        return [
            'game_version' => 'string',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
