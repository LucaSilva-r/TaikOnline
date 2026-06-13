<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'game_version',
    'season_id',
    'total_get_donmedal',
    'total_use_donmedal',
])]
class PlayerShopSeasonState extends Model
{
    protected $table = 'player_shop_season_states';

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
