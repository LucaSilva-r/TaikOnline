<?php

use App\Enums\TaikoGameVersion;

it('gates the favourite folder to Kimidori and newer', function (): void {
    expect(TaikoGameVersion::Katsudon->supportsFavoriteFolder())->toBeFalse()
        ->and(TaikoGameVersion::Sorairo->supportsFavoriteFolder())->toBeFalse()
        ->and(TaikoGameVersion::Momoiro->supportsFavoriteFolder())->toBeFalse()
        ->and(TaikoGameVersion::Kimidori->supportsFavoriteFolder())->toBeTrue()
        ->and(TaikoGameVersion::Green->supportsFavoriteFolder())->toBeTrue();
});

it('caps the favourite folder at 5 before Murasaki and 10 from Murasaki', function (): void {
    expect(TaikoGameVersion::Kimidori->favoriteSongLimit())->toBe(5)
        ->and(TaikoGameVersion::Murasaki->favoriteSongLimit())->toBe(10)
        ->and(TaikoGameVersion::Green->favoriteSongLimit())->toBe(10);
});

it('gates costume slots to Momoiro and newer', function (): void {
    expect(TaikoGameVersion::Katsudon->supportsCostumeSlots())->toBeFalse()
        ->and(TaikoGameVersion::Sorairo->supportsCostumeSlots())->toBeFalse()
        ->and(TaikoGameVersion::Momoiro->supportsCostumeSlots())->toBeTrue()
        ->and(TaikoGameVersion::Green->supportsCostumeSlots())->toBeTrue();
});

it('gates title plate backgrounds to Red and newer', function (): void {
    expect(TaikoGameVersion::White->supportsTitlePlates())->toBeFalse()
        ->and(TaikoGameVersion::Red->supportsTitlePlates())->toBeTrue()
        ->and(TaikoGameVersion::Yellow->supportsTitlePlates())->toBeTrue()
        ->and(TaikoGameVersion::Green->supportsTitlePlates())->toBeTrue();
});

it('gates profile publicity to Sorairo and newer', function (): void {
    expect(TaikoGameVersion::Katsudon->supportsProfilePublicity())->toBeFalse()
        ->and(TaikoGameVersion::Sorairo->supportsProfilePublicity())->toBeTrue()
        ->and(TaikoGameVersion::Green->supportsProfilePublicity())->toBeTrue();
});

it('gates default play options to Momoiro and tone/ranking to Murasaki', function (): void {
    expect(TaikoGameVersion::Sorairo->supportsPlayOptionDefaults())->toBeFalse()
        ->and(TaikoGameVersion::Momoiro->supportsPlayOptionDefaults())->toBeTrue()
        ->and(TaikoGameVersion::Kimidori->supportsToneDefault())->toBeFalse()
        ->and(TaikoGameVersion::Murasaki->supportsToneDefault())->toBeTrue()
        ->and(TaikoGameVersion::Kimidori->supportsRankingDifficulty())->toBeFalse()
        ->and(TaikoGameVersion::Murasaki->supportsRankingDifficulty())->toBeTrue();
});

it('gates the select-by-difficulty folder presets to White and newer', function (): void {
    expect(TaikoGameVersion::Murasaki->supportsDifficultyFolderPresets())->toBeFalse()
        ->and(TaikoGameVersion::White->supportsDifficultyFolderPresets())->toBeTrue()
        ->and(TaikoGameVersion::Green->supportsDifficultyFolderPresets())->toBeTrue();
});

it('exposes the full feature-support map for the frontend', function (): void {
    expect(TaikoGameVersion::Katsudon->featureSupport())->toMatchArray([
        'favoriteFolder' => false,
        'costumeSlots' => false,
        'playOptionDefaults' => false,
        'toneDefault' => false,
        'rankingDifficulty' => false,
        'profilePublicity' => false,
        'difficultyFolderPresets' => false,
    ]);
});
