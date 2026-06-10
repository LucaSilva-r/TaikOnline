<?php

namespace App\Enums;

enum TaikoGameVersion: string
{
    case Sorairo = 'sorairo';
    case Momoiro = 'momoiro';
    case Kimidori = 'kimidori';
    case Murasaki = 'murasaki';
    case White = 'white';
    case Red = 'red';
    case Yellow = 'yellow';
    case Blue = 'blue';
    case Green = 'green';

    public function updateIdentifier(): string
    {
        return match ($this) {
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
