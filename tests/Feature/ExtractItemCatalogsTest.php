<?php

use App\Enums\TaikoGameVersion;
use App\Services\GameItemCatalogExtractor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->sourceRoot = storage_path('framework/testing/item-dumps-'.Str::random(8));
    $this->outputRoot = storage_path('framework/testing/item-catalogs-'.Str::random(8));
});

afterEach(function (): void {
    File::deleteDirectory($this->sourceRoot);
    File::deleteDirectory($this->outputRoot);
});

function writeCatalogMusicInfo(string $path): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<boost_serialization>
  <MusicInfo>
    <Data>
      <musicid>test_song</musicid>
      <uniqueid>42</uniqueid>
      <musicname>Test Song</musicname>
      <genrename>ナムコオリジナル</genrename>
    </Data>
  </MusicInfo>
</boost_serialization>
XML);
}

function writeCatalogAsset(string $path): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '');
}

function writeNtp3Catalog(string $path, int $textureCount): void
{
    File::ensureDirectoryExists(dirname($path));
    $data = 'NTP3'."\x01\x00".pack('n', $textureCount).str_repeat("\0", 8);

    for ($index = 0; $index < $textureCount; $index++) {
        $entry = pack('N', 80)
            .str_repeat("\0", 16)
            .pack('nn', 360, 72)
            .str_repeat("\0", 48)
            .pack('N', $index)
            .str_repeat("\0", 4);
        $data .= $entry;
    }

    File::put($path, $data);
}

it('extracts exact item ids from songs, assets, and name textures', function (): void {
    $gameFolder = "{$this->sourceRoot}/SCEEX001 GREEN";
    $dataPath = "{$gameFolder}/USRDIR/data";

    writeCatalogMusicInfo("{$dataPath}/config/S11100-1/musicinfo.xml");
    writeCatalogAsset("{$dataPath}/don3d/full/cos/cos_003000.nud");
    writeCatalogAsset("{$dataPath}/don3d/parts/body/body_004000.nud");
    writeCatalogAsset("{$dataPath}/don3d/parts/head/head_005000.nud");
    writeCatalogAsset("{$dataPath}/don3d/parts/paint/paint_006000.nut");
    writeCatalogAsset("{$dataPath}/don3d/parts/acc/acc_007000.nud");
    writeNtp3Catalog(
        "{$dataPath}/nutdata/S11100-1/appendable/00/tone_name/tone_name.nut",
        3,
    );
    writeNtp3Catalog(
        "{$dataPath}/nutdata/S11100-1/appendable/00/costume_head_name/costume_head_name.nut",
        6,
    );
    writeNtp3Catalog(
        "{$dataPath}/nutdata/S11100-1/rewardgasha/acc_name_000.nut",
        8,
    );

    $catalog = app(GameItemCatalogExtractor::class)->extract(
        TaikoGameVersion::Green,
        $gameFolder,
    );

    expect($catalog['item_types']['song']['items'])->toHaveCount(1)
        ->and($catalog['item_types']['song']['items'][0])
        ->toMatchArray(['item_id' => 42, 'name' => 'Test Song', 'music_id' => 'test_song'])
        ->and(array_column($catalog['item_types']['tone']['items'], 'item_id'))->toBe([1, 2])
        ->and(array_column($catalog['item_types']['kigurumi']['items'], 'item_id'))->toBe([3])
        ->and(array_column($catalog['item_types']['body']['items'], 'item_id'))->toBe([4])
        ->and(array_column($catalog['item_types']['head']['items'], 'item_id'))->toBe([5])
        ->and($catalog['item_types']['head']['items'][0]['name_texture']['texture_index'])->toBe(5)
        ->and(array_column($catalog['item_types']['face']['items'], 'item_id'))->toBe([6])
        ->and(array_column($catalog['item_types']['puchi']['items'], 'item_id'))->toBe([7])
        ->and($catalog['item_types']['puchi']['items'][0]['name_texture']['texture_index'])->toBe(7);
});

it('writes a catalog through the artisan command', function (): void {
    $dataPath = "{$this->sourceRoot}/SCEEX001 GREEN/USRDIR/data";
    writeCatalogMusicInfo("{$dataPath}/musicinfo.xml");

    $exit = Artisan::call('app:extract-item-catalogs', [
        'version' => 'green',
        '--source' => $this->sourceRoot,
        '--output' => $this->outputRoot,
    ]);

    $outputPath = "{$this->outputRoot}/green/green_item_catalog.json";

    expect($exit)->toBe(0)
        ->and(File::exists($outputPath))->toBeTrue()
        ->and(json_decode(File::get($outputPath), true, flags: JSON_THROW_ON_ERROR))
        ->toHaveKeys(['schema_version', 'game_version', 'source_game_folder', 'item_types']);
});

it('fails when the source root or version is invalid', function (): void {
    expect(Artisan::call('app:extract-item-catalogs', [
        '--source' => "{$this->sourceRoot}/missing",
    ]))->toBe(1)
        ->and(Artisan::call('app:extract-item-catalogs', [
            'version' => 'invalid',
            '--source' => $this->sourceRoot,
        ]))->toBe(1);
});
