<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'game_version',
    'season_id',
    'item_type',
    'item_id',
    'item_no',
    'item_price',
    'purchased_at',
])]
class PlayerShopItem extends Model
{
    protected $table = 'player_shop_items';

    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
