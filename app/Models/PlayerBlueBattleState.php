<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'release_info_flg',
    'release_battle_stage_flg',
    'last_battle_stage_id',
    'last_boss_life',
    'last_npc_id',
    'assign_stage_id',
])]
class PlayerBlueBattleState extends Model
{
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
