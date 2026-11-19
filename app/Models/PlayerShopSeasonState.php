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

    /**
     * The card's Don Medal wallet for a version. Medals carry over between seasons, so the
     * balance sums every season row; the rows only record where medals were earned and spent.
     * Pass $lock inside a transaction that spends.
     *
     * @return array{get: int, use: int}
     */
    public static function wallet(int $baid, string $gameVersion, bool $lock = false): array
    {
        $query = self::query()->where('baid', $baid)->where('game_version', $gameVersion);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get(['total_get_donmedal', 'total_use_donmedal']);

        return [
            'get' => (int) $rows->sum('total_get_donmedal'),
            'use' => (int) $rows->sum('total_use_donmedal'),
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
