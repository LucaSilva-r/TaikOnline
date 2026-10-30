<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A dan course's play, scored by the server from its songs' replays. */
class WdbDanPlay extends Model
{
    use HasUuids;

    /** The scorer's result levels. */
    public const FAILED = 0;

    public const RED = 1;

    public const GOLD = 2;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['played_at' => 'datetime', 'rescored_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    /** @return HasMany<WdbDanPlaySong, $this> */
    public function songs(): HasMany
    {
        return $this->hasMany(WdbDanPlaySong::class)->orderBy('position');
    }
}
