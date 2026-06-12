<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'npc_id',
    'total_exp',
    'max_dpn',
    'npc_costume_id',
    'npc_costume_flg',
    'selected_special_id_1',
    'selected_special_id_2',
    'selected_special_id_3',
    'release_special_flg',
    'bonds_level',
])]
class PlayerBlueBattleNpcState extends Model
{
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
