<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports all songs from green musicinfo.xml', function (): void {
    $output = Artisan::call('app:import-songs', ['version' => 'green']);

    expect($output)->toBe(0)
        ->and(Song::where('version', 'green')->count())->toBe(853);
});

it('correctly maps song fields from XML data', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $song = Song::where('version', 'green')
        ->where('music_id', 'ynzums')
        ->first();

    expect($song)->not->toBeNull()
        ->and($song->version)->toBe('green')
        ->and($song->song_no)->toBe(873)
        ->and($song->music_id)->toBe('ynzums')
        ->and($song->unique_id)->toBe(873)
        ->and($song->title)->toBe('馬と鹿')
        ->and($song->genre)->toBe(SongGenre::Jpop)
        ->and($song->partsset)->toBe(SongPartsSet::Taiko)
        ->and($song->wai2_partsset)->toBe(SongWai2PartsSet::Taiko)
        ->and($song->flags['hasextreme'])->toBeFalse()
        ->and($song->flags['papamama'])->toBeFalse()
        ->and($song->flags['secret'])->toBeFalse()
        ->and($song->flags['newrelease'])->toBeTrue()
        ->and($song->flags['demoplay'])->toBeFalse();
});

it('accepts update identifiers and stores the canonical version', function (): void {
    $output = Artisan::call('app:import-songs', ['version' => 'ST-11100-1']);

    expect($output)->toBe(0)
        ->and(Song::where('version', 'green')->count())->toBe(853)
        ->and(Song::where('version', 'ST-11100-1')->count())->toBe(0);
});

it('correctly handles songs with multiple flag types', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $evedrm = Song::where('version', 'green')
        ->where('music_id', 'evedrm')
        ->first();

    expect($evedrm)->not->toBeNull()
        ->and($evedrm->flags['hasextreme'])->toBeTrue()
        ->and($evedrm->title)->toBe('ドラマツルギー');

    $manpu2 = Song::where('version', 'green')
        ->where('music_id', 'manpu2')
        ->first();

    expect($manpu2)->not->toBeNull()
        ->and($manpu2->flags['papamama'])->toBeTrue()
        ->and($manpu2->flags['hasextreme'])->toBeTrue();
});

it('resolves all unique genre values correctly', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $xml = simplexml_load_file(storage_path('app/game-data/green/musicinfo.xml'));
    $genreNames = [];
    foreach ($xml->MusicInfo->Data as $data) {
        $genreNames[] = (string) $data->genrename;
    }
    $uniqueGenres = array_unique($genreNames);

    $storedGenres = Song::where('version', 'green')
        ->pluck('genre')
        ->map(fn ($e) => $e->value)
        ->unique()
        ->all();

    expect($storedGenres)->toHaveCount(count($uniqueGenres))
        ->and(SongGenre::cases())->toHaveCount(9);
});

it('resolves all unique partsset values correctly', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $xml = simplexml_load_file(storage_path('app/game-data/green/musicinfo.xml'));
    $partsSetNames = [];
    foreach ($xml->MusicInfo->Data as $data) {
        $partsSetNames[] = (string) $data->partsset;
    }
    $uniquePartsSets = array_unique($partsSetNames);

    $storedPartsSets = Song::where('version', 'green')
        ->pluck('partsset')
        ->map(fn ($e) => $e->value)
        ->unique()
        ->all();

    expect($storedPartsSets)->toHaveCount(count($uniquePartsSets))
        ->and(SongPartsSet::cases())->toHaveCount(32);
});

it('resolves all unique wai2_partsset values correctly', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $xml = simplexml_load_file(storage_path('app/game-data/green/musicinfo.xml'));
    $wai2Names = [];
    foreach ($xml->MusicInfo->Data as $data) {
        $wai2Names[] = (string) $data->wai2partsset;
    }
    $uniqueWai2s = array_unique($wai2Names);

    $storedWai2s = Song::where('version', 'green')
        ->pluck('wai2_partsset')
        ->map(fn ($e) => $e->value)
        ->unique()
        ->all();

    expect($storedWai2s)->toHaveCount(count($uniqueWai2s))
        ->and(SongWai2PartsSet::cases())->toHaveCount(8);
});

it('stores and preserves tags array correctly', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $natsu = Song::where('version', 'green')
        ->where('music_id', 'natsu')
        ->first();

    expect($natsu)->not->toBeNull()
        ->and($natsu->tags)->toHaveCount(16)
        ->and(count(array_filter($natsu->tags, fn ($t) => $t > 0)))->toBeGreaterThan(0);
});

it('stores tags array with correct length for all songs', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $allCorrect = Song::where('version', 'green')
        ->get()
        ->every(fn (Song $song) => count($song->tags) === 16);

    expect($allCorrect)->toBeTrue();

    // Verify songs with non-zero tags have correct sums
    $dchero = Song::where('version', 'green')
        ->where('music_id', 'dchero')
        ->first();

    expect($dchero)->not->toBeNull()
        ->and($dchero->tags)->toHaveCount(16)
        ->and(array_sum($dchero->tags))->toBeGreaterThan(0);
});

it('upserts songs on re-import', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);
    $firstCount = Song::where('version', 'green')->count();

    Artisan::call('app:import-songs', ['version' => 'green']);
    $secondCount = Song::where('version', 'green')->count();

    expect($secondCount)->toBe($firstCount)
        ->and(Song::where('version', 'green')->count())->toBe(853);
});

it('maintains data integrity after re-import', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    $songBefore = Song::where('version', 'green')
        ->where('music_id', 'ynzums')
        ->first();

    Artisan::call('app:import-songs', ['version' => 'green']);

    $songAfter = Song::where('version', 'green')
        ->where('music_id', 'ynzums')
        ->first();

    expect($songBefore->unique_id)->toBe($songAfter->unique_id)
        ->and($songBefore->title)->toBe($songAfter->title)
        ->and($songBefore->genre)->toBe($songAfter->genre)
        ->and($songBefore->partsset)->toBe($songAfter->partsset);
});

it('isolates songs by version', function (): void {
    Artisan::call('app:import-songs', ['version' => 'green']);

    expect(Song::where('version', 'green')->count())->toBe(853)
        ->and(Song::where('version', 'blue')->count())->toBe(0)
        ->and(Song::where('version', 'test-version')->count())->toBe(0);
});

it('reports errors for unknown genre gracefully', function (): void {
    // Create a temp XML with an invalid genre
    $rootDir = storage_path('app/game-data-test-invalid');
    $tempDir = "{$rootDir}/green";
    mkdir($tempDir, 0755, true);
    config()->set('taiko_green.data_path', $rootDir);

    $xmlContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<boost_serialization signature="serialization::archive" version="10">
<MusicInfo class_id="0" tracking_level="0" version="0">
<Data>
<musicid>test1</musicid><uniqueid>999</uniqueid><newrelease>0</newrelease><secret>0</secret>
<papamama>0</papamama><hasextreme>0</hasextreme><partsset>taiko</partsset><wai2partsset>taiko</wai2partsset>
<musicname>Test Song</musicname><genrename>UnknownGenre</genrename><demoplay>0</demoplay>
<tag>0</tag><tag>0</tag><tag>0</tag><tag>0</tag><tag>13</tag><tag>0</tag>
<tag>0</tag><tag>0</tag><tag>0</tag><tag>55</tag><tag>0</tag><tag>0</tag>
<tag>0</tag><tag>0</tag><tag>0</tag><tag>0</tag>
</Data></MusicInfo></boost_serialization>';

    file_put_contents("{$tempDir}/musicinfo.xml", $xmlContent);

    $output = Artisan::call('app:import-songs', ['version' => 'green']);

    expect($output)->toBe(1)
        ->and(Song::where('version', 'green')->count())->toBe(0);

    // Cleanup
    unlink("{$tempDir}/musicinfo.xml");
    rmdir($tempDir);
    rmdir($rootDir);
});

it('fails when xml file does not exist', function (): void {
    $output = Artisan::call('app:import-songs', ['version' => 'nonexistent-version']);

    expect($output)->toBe(1);
});
