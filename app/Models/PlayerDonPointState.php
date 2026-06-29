<?php

namespace App\Models;

use App\Enums\TaikoGameVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'baid',
    'game_version',
    'total_get_donpoint',
    'total_use_donpoint',
    'reward_ptn',
    'reward_progress',
])]
class PlayerDonPointState extends Model
{
    protected function casts(): array
    {
        return [
            'game_version' => 'string',
        ];
    }

    public static function resolve(int $baid, TaikoGameVersion $version): self
    {
        return self::query()->firstOrNew([
            'baid' => $baid,
            'game_version' => $version->value,
        ]);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
