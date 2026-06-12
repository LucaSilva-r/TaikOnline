<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerTokkunState extends Model
{
    protected $fillable = [
        'baid',
        'game_version',
        'tokkun_tutorial_flg',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
