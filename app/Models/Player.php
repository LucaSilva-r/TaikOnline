<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'mydon_name',
    'mydon_name_language',
    'color_face',
    'color_body',
    'color_limb',
    'favorite_song_numbers',
    'recent_song_numbers',
    'unlocked_song_numbers',
    'difficulty_played_course',
    'difficulty_played_star',
    'difficulty_played_sort',
    'total_credit_count',
    'total_get_donmedal',
    'total_use_donmedal',
    'total_get_katsumedal',
    'total_use_katsumedal',
    'last_played_at',
    'access_token',
    'person_id',
    'user_id',
])]
class Player extends Model
{
    protected $primaryKey = 'baid';

    protected function casts(): array
    {
        return [
            'favorite_song_numbers' => 'array',
            'recent_song_numbers' => 'array',
            'unlocked_song_numbers' => 'array',
            'last_played_at' => 'datetime',
        ];
    }

    public function card(): HasOne
    {
        return $this->hasOne(GameCard::class, 'baid', 'baid');
    }

    public function playResults(): HasMany
    {
        return $this->hasMany(SongPlayResult::class, 'baid', 'baid');
    }

    public function songBests(): HasMany
    {
        return $this->hasMany(SongBest::class, 'baid', 'baid');
    }

    public function cosmetics(): HasMany
    {
        return $this->hasMany(PlayerCosmetic::class, 'baid', 'baid');
    }

    public function blueBattleState(): HasOne
    {
        return $this->hasOne(PlayerBlueBattleState::class, 'baid', 'baid');
    }

    public function blueBattleNpcStates(): HasMany
    {
        return $this->hasMany(PlayerBlueBattleNpcState::class, 'baid', 'baid');
    }

    public function blueBattleTokenStates(): HasMany
    {
        return $this->hasMany(PlayerBlueBattleTokenState::class, 'baid', 'baid');
    }

    public function greenGhostState(): HasOne
    {
        return $this->hasOne(PlayerGreenGhostState::class, 'baid', 'baid');
    }

    public function greenGhostWinnings(): HasMany
    {
        return $this->hasMany(PlayerGreenGhostWinnings::class, 'baid', 'baid');
    }

    public function greenGhostTokens(): HasMany
    {
        return $this->hasMany(PlayerGreenGhostToken::class, 'baid', 'baid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
