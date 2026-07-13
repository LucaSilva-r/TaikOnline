<?php

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\TaikoTitleCatalog;

function titleCatalog(): TaikoTitleCatalog
{
    return new TaikoTitleCatalog(dirname(__DIR__, 2).'/resources/game-data/title-catalog.json');
}

it('provides the catalog range available to each game version', function (): void {
    $catalog = titleCatalog();

    expect($catalog->find(TaikoGameVersion::Momoiro, 298))->not->toBeNull()
        ->and($catalog->find(TaikoGameVersion::Momoiro, 400))->toBeNull()
        ->and($catalog->find(TaikoGameVersion::Murasaki, 400))->not->toBeNull()
        ->and($catalog->find(TaikoGameVersion::Green, 760))->toBe([
            'id' => 760,
            'name' => '「太鼓をたたいてスタート！」',
            'plate' => 2,
        ]);
});

it('ends each version catalog at its extracted title range', function (string $version, int $lastTitleId): void {
    $catalog = titleCatalog();
    $gameVersion = TaikoGameVersion::from($version);

    expect($catalog->find($gameVersion, $lastTitleId))->not->toBeNull()
        ->and($catalog->find($gameVersion, $lastTitleId + 1))->toBeNull();
})->with([
    'Momoiro' => ['momoiro', 298],
    'Kimidori' => ['kimidori', 335],
    'Murasaki' => ['murasaki', 404],
    'White' => ['white', 455],
    'Red' => ['red', 507],
    'Yellow' => ['yellow', 564],
    'Blue' => ['blue', 625],
    'Green' => ['green', 760],
]);

it('excludes titles missing from a version files', function (): void {
    $catalog = titleCatalog();

    expect($catalog->find(TaikoGameVersion::Blue, 603))->toBeNull()
        ->and($catalog->find(TaikoGameVersion::Blue, 604))->toBeNull()
        ->and($catalog->find(TaikoGameVersion::Blue, 605))->toBeNull()
        ->and($catalog->find(TaikoGameVersion::Green, 603))->not->toBeNull();
});

it('contains only title plates supported by GEN 3', function (): void {
    $catalog = titleCatalog();
    $plates = array_column($catalog->titles(TaikoGameVersion::Green), 'plate');

    expect(min($plates))->toBe(0)
        ->and(max($plates))->toBe(3);
});
