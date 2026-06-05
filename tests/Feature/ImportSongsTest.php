<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->dataPath = storage_path('framework/testing/game-data-'.Str::random(8));
    config()->set('taiko_green.data_path', $this->dataPath);
});

afterEach(function (): void {
    if (is_dir($this->dataPath)) {
        exec('rm -rf '.escapeshellarg($this->dataPath));
    }
});

/**
 * @param  array<int, array<string, mixed>>  $songs
 */
function musicinfoXml(array $songs): string
{
    $body = '';
    foreach ($songs as $song) {
        $tags = '';
        for ($i = 0; $i < 16; $i++) {
            $tags .= '<tag>'.(int) ($song['tags'][$i] ?? 0).'</tag>';
        }
        $wai2 = array_key_exists('wai2', $song) ? "<wai2partsset>{$song['wai2']}</wai2partsset>" : '';
        $body .= '<Data>'
            ."<musicid>{$song['musicid']}</musicid>"
            ."<uniqueid>{$song['uniqueid']}</uniqueid>"
            .'<newrelease>'.(int) ($song['newrelease'] ?? 0).'</newrelease>'
            .'<secret>'.(int) ($song['secret'] ?? 0).'</secret>'
            .'<papamama>'.(int) ($song['papamama'] ?? 0).'</papamama>'
            .'<hasextreme>'.(int) ($song['hasextreme'] ?? 0).'</hasextreme>'
            ."<partsset>{$song['partsset']}</partsset>"
            .$wai2
            ."<musicname>{$song['title']}</musicname>"
            ."<genrename>{$song['genre']}</genrename>"
            .'<demoplay>'.(int) ($song['demoplay'] ?? 0).'</demoplay>'
            .$tags
            .'</Data>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<boost_serialization signature="serialization::archive" version="10">'
        .'<MusicInfo class_id="0" tracking_level="0" version="0">'.$body.'</MusicInfo>'
        .'</boost_serialization>';
}

/**
 * @param  array<int, array<string, mixed>>  $songs
 */
function writeMusicinfo(string $path, array $songs): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, musicinfoXml($songs));
}

it('imports songs and maps every field', function (): void {
    writeMusicinfo("{$this->dataPath}/green/musicinfo.xml", [
        ['musicid' => 'aaa', 'uniqueid' => 873, 'title' => '馬と鹿', 'genre' => 'J-POP', 'partsset' => 'taiko', 'wai2' => 'taiko', 'newrelease' => 1, 'tags' => [0, 0, 0, 0, 13]],
    ]);

    $exit = Artisan::call('app:import-songs', ['version' => 'green']);
    $song = Song::query()->where('version', 'green')->where('music_id', 'aaa')->first();

    expect($exit)->toBe(0)
        ->and($song->song_no)->toBe(873)
        ->and($song->unique_id)->toBe(873)
        ->and($song->title)->toBe('馬と鹿')
        ->and($song->genre)->toBe(SongGenre::Jpop)
        ->and($song->partsset)->toBe(SongPartsSet::Taiko)
        ->and($song->wai2_partsset)->toBe(SongWai2PartsSet::Taiko)
        ->and($song->flags['newrelease'])->toBeTrue()
        ->and($song->flags['hasextreme'])->toBeFalse()
        ->and($song->tags)->toHaveCount(16)
        ->and($song->tags[4])->toBe(13);
});

it('accepts an update identifier and stores the canonical version', function (): void {
    writeMusicinfo("{$this->dataPath}/green/musicinfo.xml", [
        ['musicid' => 'aaa', 'uniqueid' => 1, 'title' => 'A', 'genre' => 'J-POP', 'partsset' => 'taiko'],
    ]);

    $exit = Artisan::call('app:import-songs', ['version' => 'ST-11100-1']);

    expect($exit)->toBe(0)
        ->and(Song::query()->where('version', 'green')->count())->toBe(1)
        ->and(Song::query()->where('version', 'ST-11100-1')->count())->toBe(0);
});

it('stores an empty wai2 partsset when the element is absent', function (): void {
    // The reduced base musicinfo (older versions) omits wai2partsset entirely.
    writeMusicinfo("{$this->dataPath}/sorairo/musicinfo.xml", [
        ['musicid' => 'aaa', 'uniqueid' => 1, 'title' => 'A', 'genre' => 'ナムコオリジナル', 'partsset' => 'taiko'],
    ]);

    $exit = Artisan::call('app:import-songs', ['version' => 'sorairo']);
    $song = Song::query()->where('version', 'sorairo')->first();

    expect($exit)->toBe(0)
        ->and($song->wai2_partsset)->toBe(SongWai2PartsSet::None)
        ->and($song->genre)->toBe(SongGenre::NamcoOriginal);
});

it('skips unknown genre or partsset rows without failing', function (): void {
    writeMusicinfo("{$this->dataPath}/green/musicinfo.xml", [
        ['musicid' => 'good', 'uniqueid' => 1, 'title' => 'A', 'genre' => 'J-POP', 'partsset' => 'taiko'],
        ['musicid' => 'badgenre', 'uniqueid' => 2, 'title' => 'B', 'genre' => 'Nope', 'partsset' => 'taiko'],
        ['musicid' => 'badparts', 'uniqueid' => 3, 'title' => 'C', 'genre' => 'J-POP', 'partsset' => 'nope'],
    ]);

    $exit = Artisan::call('app:import-songs', ['version' => 'green']);

    expect($exit)->toBe(0)
        ->and(Song::query()->where('version', 'green')->count())->toBe(1)
        ->and(Song::query()->where('music_id', 'good')->exists())->toBeTrue();
});

it('upserts songs idempotently on re-import', function (): void {
    writeMusicinfo("{$this->dataPath}/green/musicinfo.xml", [
        ['musicid' => 'aaa', 'uniqueid' => 1, 'title' => 'A', 'genre' => 'J-POP', 'partsset' => 'taiko'],
        ['musicid' => 'bbb', 'uniqueid' => 2, 'title' => 'B', 'genre' => 'J-POP', 'partsset' => 'taiko'],
    ]);

    Artisan::call('app:import-songs', ['version' => 'green']);
    Artisan::call('app:import-songs', ['version' => 'green']);

    expect(Song::query()->where('version', 'green')->count())->toBe(2);
});

it('imports every version when no version is given', function (): void {
    writeMusicinfo("{$this->dataPath}/green/musicinfo.xml", [
        ['musicid' => 'g1', 'uniqueid' => 1, 'title' => 'G', 'genre' => 'J-POP', 'partsset' => 'taiko'],
    ]);
    writeMusicinfo("{$this->dataPath}/blue/musicinfo.xml", [
        ['musicid' => 'b1', 'uniqueid' => 1, 'title' => 'B', 'genre' => 'J-POP', 'partsset' => 'taiko'],
        ['musicid' => 'b2', 'uniqueid' => 2, 'title' => 'B2', 'genre' => 'J-POP', 'partsset' => 'taiko'],
    ]);

    $exit = Artisan::call('app:import-songs');

    expect($exit)->toBe(0)
        ->and(Song::query()->where('version', 'green')->count())->toBe(1)
        ->and(Song::query()->where('version', 'blue')->count())->toBe(2)
        ->and(Song::query()->where('version', 'red')->count())->toBe(0);
});

it('copies the fullest musicinfo from a game dump via --source', function (): void {
    $source = storage_path('framework/testing/dump-'.Str::random(8));
    $folder = "{$source}/SCEEXE001 GREEN/USRDIR/data";
    // Base list is reduced; the board-config variant is the authoritative catalog.
    writeMusicinfo("{$folder}/musicinfo.xml", [
        ['musicid' => 'base1', 'uniqueid' => 1, 'title' => 'A', 'genre' => 'J-POP', 'partsset' => 'taiko'],
    ]);
    writeMusicinfo("{$folder}/config/S11100-1/musicinfo.xml", [
        ['musicid' => 'full1', 'uniqueid' => 1, 'title' => 'A', 'genre' => 'J-POP', 'partsset' => 'taiko'],
        ['musicid' => 'full2', 'uniqueid' => 2, 'title' => 'B', 'genre' => 'J-POP', 'partsset' => 'taiko'],
    ]);

    $exit = Artisan::call('app:import-songs', ['version' => 'green', '--source' => $source]);

    expect($exit)->toBe(0)
        ->and(file_exists("{$this->dataPath}/green/musicinfo.xml"))->toBeTrue()
        ->and(Song::query()->where('version', 'green')->count())->toBe(2)
        ->and(Song::query()->where('music_id', 'full2')->exists())->toBeTrue();

    exec('rm -rf '.escapeshellarg($source));
});

it('fails for an unknown version argument', function (): void {
    expect(Artisan::call('app:import-songs', ['version' => 'nonexistent']))->toBe(1);
});

it('fails when the file is missing for an explicitly requested version', function (): void {
    expect(Artisan::call('app:import-songs', ['version' => 'green']))->toBe(1);
});
