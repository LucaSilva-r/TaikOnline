<?php

namespace App\Models;

use App\Enums\TaikoGameVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version-scoped cosmetic loadout for a card: equipped costume parts plus the
 * costume/tone/title unlock lists, keyed per (baid, game_version) because the
 * id-to-item mappings differ between Taiko versions.
 */
#[Fillable([
    'baid',
    'game_version',
    'title',
    'titleplate_id',
    'default_tone_setting',
    'default_option_setting',
    'costume_1',
    'costume_2',
    'costume_3',
    'costume_4',
    'costume_5',
    'unlocked_costumes',
    'unlocked_tones',
    'unlocked_titles',
])]
class PlayerCosmetic extends Model
{
    protected function casts(): array
    {
        return [
            'game_version' => 'string',
            'unlocked_costumes' => 'array',
            'unlocked_tones' => 'array',
            'unlocked_titles' => 'array',
        ];
    }

    /**
     * Resolve (without persisting) the cosmetic row for a card and version,
     * returning a fresh instance when the player has never played that version.
     */
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
