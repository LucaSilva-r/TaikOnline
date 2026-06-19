<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerFavoriteSong extends Model
{
    protected $fillable = [
        'baid',
        'game_version',
        'song_no',
    ];

    protected function casts(): array
    {
        return [
            'song_no' => 'integer',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
