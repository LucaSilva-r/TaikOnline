<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'game_version',
    'rank',
    'total_score',
    'ranked_song_count',
    'played_song_count',
    'crown_counts',
    'snapshot_date',
])]
class PlayerRankSnapshot extends Model
{
    protected $attributes = [
        'total_score' => 0,
        'ranked_song_count' => 0,
        'played_song_count' => 0,
        'crown_counts' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'crown_counts' => 'array',
            'snapshot_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
