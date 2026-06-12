<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'release_info_flag',
    'total_winnings',
    'input_median',
    'input_variance',
    'rank_id',
    'win_point',
    'certified_level_id',
])]
class PlayerGreenGhostState extends Model
{
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
