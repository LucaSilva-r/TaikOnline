<?php

namespace App\Enums;

enum TaikoGameVersion: string
{
    case Katsudon = 'katsudon';
    case Sorairo = 'sorairo';
    case Momoiro = 'momoiro';
    case Kimidori = 'kimidori';
    case Murasaki = 'murasaki';
    case White = 'white';
    case Red = 'red';
    case Yellow = 'yellow';
    case Blue = 'blue';
    case Green = 'green';

    public static function default(): self
    {
        return self::Green;
    }

    /**
     * Chronological generation index, derived from enum declaration order
     * (Katsudon is oldest, Green is newest). Note: the 2011 launch version
     * (初代 / 無印) predates Katsudon and is intentionally not modelled here.
     */
    public function generation(): int
    {
        return array_search($this, self::cases(), true);
    }

    /**
     * Whether this version is the same as or newer than the given version.
     */
    public function isAtLeast(self $other): bool
    {
        return $this->generation() >= $other->generation();
    }

    /**
     * Whether the cabinet song-select exposes a favourite-song folder at all.
     * The folder debuts in Kimidori (V0.12); KATSU-DON, Sorairo and Momoiro
     * have no such folder, so favourites must not be offered there.
     */
    public function supportsFavoriteFolder(): bool
    {
        return $this->isAtLeast(self::Kimidori);
    }

    /**
     * Maximum number of songs the favourite folder holds. Kimidori/White cap at
     * 5, Murasaki and newer at 10. Only meaningful when
     * {@see supportsFavoriteFolder()} is true.
     */
    public function favoriteSongLimit(): int
    {
        return $this->isAtLeast(self::Murasaki) ? 10 : 5;
    }

    /**
     * Whether costume customization exists on the website. The からだ/あたま/メイク
     * slot system, きぐるみ and the reward shop debut in Momoiro; earlier versions
     * only had a single card-applied きせかえ with no website editor.
     */
    public function supportsCostumeSlots(): bool
    {
        return $this->isAtLeast(self::Momoiro);
    }

    /**
     * Whether the profile protocol accepts a selectable title plate id.
     * Title plate backgrounds debut in Red and retain the same ids through Green.
     */
    public function supportsTitlePlates(): bool
    {
        return $this->isAtLeast(self::Red);
    }

    /**
     * Whether donderhiroba lets the player set default enso (play) options.
     * Debuts in Momoiro.
     */
    public function supportsPlayOptionDefaults(): bool
    {
        return $this->isAtLeast(self::Momoiro);
    }

    /**
     * Whether donderhiroba lets the player set a default taiko tone (音色).
     * Debuts in Murasaki.
     */
    public function supportsToneDefault(): bool
    {
        return $this->isAtLeast(self::Murasaki);
    }

    /**
     * Whether the player can pick which difficulty the in-arcade ranking shows.
     * Debuts in Murasaki.
     */
    public function supportsRankingDifficulty(): bool
    {
        return $this->isAtLeast(self::Murasaki);
    }

    /**
     * Whether the gender/birthday publicity toggle exists. Debuts on donderhiroba
     * in Sorairo.
     */
    public function supportsProfilePublicity(): bool
    {
        return $this->isAtLeast(self::Sorairo);
    }

    /**
     * Whether the "select by difficulty" (むずかしさからえらぶ) folder and its
     * presets exist. The folder debuts in White.
     */
    public function supportsDifficultyFolderPresets(): bool
    {
        return $this->isAtLeast(self::White);
    }

    /**
     * Feature-availability map shared with the frontend so the website can hide
     * controls for versions that never had a given feature.
     *
     * @return array{
     *     favoriteFolder: bool,
     *     favoriteLimit: int,
     *     costumeSlots: bool,
     *     playOptionDefaults: bool,
     *     toneDefault: bool,
     *     rankingDifficulty: bool,
     *     profilePublicity: bool,
     *     difficultyFolderPresets: bool,
     * }
     */
    public function featureSupport(): array
    {
        return [
            'favoriteFolder' => $this->supportsFavoriteFolder(),
            'favoriteLimit' => $this->favoriteSongLimit(),
            'costumeSlots' => $this->supportsCostumeSlots(),
            'playOptionDefaults' => $this->supportsPlayOptionDefaults(),
            'toneDefault' => $this->supportsToneDefault(),
            'rankingDifficulty' => $this->supportsRankingDifficulty(),
            'profilePublicity' => $this->supportsProfilePublicity(),
            'difficultyFolderPresets' => $this->supportsDifficultyFolderPresets(),
        ];
    }

    public function updateIdentifier(): string
    {
        return match ($this) {
            self::Katsudon => 'ST2100-1',
            self::Sorairo => 'ST3100-1',
            self::Momoiro => 'ST4100-1',
            self::Kimidori => 'ST5100-1',
            self::Murasaki => 'ST6100-1',
            self::White => 'ST7100-1',
            self::Red => 'ST8100-1',
            self::Yellow => 'ST-9100-1',
            self::Blue => 'ST-10100-1',
            self::Green => 'ST-11100-1',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Katsudon => 'KATSUDON',
            self::Sorairo => 'SORAIRO',
            self::Momoiro => 'MOMOIRO',
            self::Kimidori => 'KIMIDORI',
            self::Murasaki => 'MURASAKI',
            self::White => 'WHITE',
            self::Red => 'RED',
            self::Yellow => 'YELLOW',
            self::Blue => 'BLUE',
            self::Green => 'GREEN',
        };
    }

    /**
     * StudlyCase segment used for this version's generated protobuf namespace,
     * e.g. App\GameProtocol\Proto\{Studly}\Taiko.
     */
    public function namespaceSegment(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Major route prefix the cabinet sends in its URL (e.g. "v11"), derived
     * from the ST update identifier (ST<NN>100 => vNN).
     */
    public function routeMajor(): string
    {
        preg_match('/ST-?(\d+)100/', $this->updateIdentifier(), $matches);

        return sprintf('v%02d', (int) ($matches[1] ?? 0));
    }

    /**
     * Resolve the game version from a route version such as "v11r00" or
     * "v10r02_tw" by matching its major prefix against each case.
     */
    public static function fromRouteVersion(string $routeVersion): ?self
    {
        if (preg_match('/^(v\d{2})/', strtolower(trim($routeVersion)), $matches) !== 1) {
            return null;
        }

        foreach (self::cases() as $version) {
            if ($version->routeMajor() === $matches[1]) {
                return $version;
            }
        }

        return null;
    }

    public static function fromInput(string $value): ?self
    {
        $normalized = self::normalize($value);

        $version = self::tryFrom($normalized);
        if ($version instanceof self) {
            return $version;
        }

        foreach (self::cases() as $version) {
            if (self::normalize($version->updateIdentifier()) === $normalized) {
                return $version;
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return str($value)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->toString();
    }
}
