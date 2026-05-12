<?php

namespace App\Enums;

enum TaikoGameVersion: string
{
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
            self::Kimidori => 'KIMIDORI',
            self::Murasaki => 'MURASAKI',
            self::White => 'WHITE',
            self::Red => 'RED',
            self::Yellow => 'YELLOW',
            self::Blue => 'BLUE',
            self::Green => 'GREEN',
        };
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
