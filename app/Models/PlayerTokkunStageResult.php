<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerTokkunStageResult extends Model
{
    protected $fillable = [
        'baid',
        'game_version',
        'played_at',
        'play_mode',
        'banacoin_datetime',
        'tokkun_song_count',
        'tokkun_song_numbers',
        'tokkun_speedchange_count',
        'tokkun_autoplay_count',
        'tokkun_jump_count',
    ];

    protected $casts = [
        'played_at' => 'datetime',
        'tokkun_song_numbers' => 'array',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
