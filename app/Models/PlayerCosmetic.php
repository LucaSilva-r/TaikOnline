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
    'costume_presets',
    'active_costume_preset',
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
            'costume_presets' => 'array',
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

    /** Number of きせかえセット preset slots the cabinet exposes. */
    public const PRESET_COUNT = 3;

    /**
     * The equipped-part keys that make up one preset. Slot 4 (face) is omitted
     * because no make-up items exist yet.
     *
     * @var array<int, string>
     */
    public const PRESET_KEYS = ['costume_1', 'costume_2', 'costume_3', 'costume_5'];

    /**
     * Always-three preset sets, padded/truncated from stored data so the UI and
     * the cabinet's ary_favorite_costumedata have a stable shape.
     *
     * @return array<int, array<string, int>>
     */
    public function normalizedPresets(): array
    {
        $stored = $this->costume_presets ?? [];
        $presets = [];

        for ($i = 0; $i < self::PRESET_COUNT; $i++) {
            $set = is_array($stored[$i] ?? null) ? $stored[$i] : [];
            $presets[$i] = array_map(
                fn (string $key): int => (int) ($set[$key] ?? 0),
                array_combine(self::PRESET_KEYS, self::PRESET_KEYS)
            );
        }

        return $presets;
    }
}
