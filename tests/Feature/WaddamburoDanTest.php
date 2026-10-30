<?php

use App\Models\WdbDanCourse;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->dataFolder = (string) Str::uuid();
});

afterEach(function (): void {
    foreach (File::glob(storage_path('framework/testing/dan-*')) as $folder) {
        File::deleteDirectory($folder);
    }
});

/** Runs the import against a throwaway data folder, so tests never touch the committed files. */
function dan_import(string $series, string $file)
{
    return test()->artisan('app:import-waddamburo-dan', ['series' => $series, 'file' => $file, '--data' => storage_path('framework/testing/dan-data-'.test()->dataFolder)]);
}

function dan_xml(string $firstSong = 'umzaso', int $goldHits = 460, string $end = '</boost_serialization>'): string
{
    $root = storage_path('framework/testing/dan-'.Str::uuid());
    File::ensureDirectoryExists($root);
    $path = "{$root}/musicmedleyinfo.xml";
    File::put($path, <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <boost_serialization>
          <MusicMedleyInfoData>
            <uniqueid>20001</uniqueid>
            <medleyname>初級</medleyname>
            <difficulty>3</difficulty>
            <challengelv>1</challengelv>
            <Content><musicid>{$firstSong}</musicid><uniqueid>790</uniqueid><difficulty>0</difficulty><notes>138</notes></Content>
            <Content><musicid>saibou</musicid><uniqueid>791</uniqueid><difficulty>0</difficulty><notes>128</notes></Content>
            <Content><musicid>eva</musicid><uniqueid>135</uniqueid><difficulty>0</difficulty><notes>103</notes></Content>
            <Conditions><tamashii>9000</tamashii><hit_ryo>0</hit_ryo><hit_ka>0</hit_ka><hit_fuka>0</hit_fuka><combo>0</combo><hits>420</hits><score>0</score><renda>0</renda></Conditions>
            <ExcellentConditions><tamashii>9500</tamashii><hit_ryo>0</hit_ryo><hit_ka>0</hit_ka><hit_fuka>0</hit_fuka><combo>0</combo><hits>{$goldHits}</hits><score>0</score><renda>0</renda></ExcellentConditions>
          </MusicMedleyInfoData>
        {$end}
        XML);

    return $path;
}

it('imports a series with its requirements', function (): void {
    dan_import('green', dan_xml())->assertSuccessful();

    $course = WdbDanCourse::query()->where('series', 'green')->where('level', 1)->sole();
    expect($course->name)->toBe('初級')
        ->and($course->requirements)->toBe([
            ['type' => 'soul_gauge', 'red' => [90], 'gold' => [95]],
            ['type' => 'hits', 'red' => [420], 'gold' => [460]],
        ])
        ->and($course->songs->pluck('song_key')->all())->toBe(['umzaso', 'saibou', 'eva']);
});

it('turns KATSU-DON\'s or-less counts into the under limits of later lists', function (): void {
    $file = dan_xml();
    File::put($file, str_replace(
        ['<hit_fuka>0</hit_fuka><combo>0</combo><hits>420</hits>', '<hit_fuka>0</hit_fuka><combo>0</combo><hits>460</hits>'],
        ['<hit_fuka>100</hit_fuka><combo>0</combo><hits>0</hits>', '<hit_fuka>0</hit_fuka><combo>0</combo><hits>0</hits>'],
        File::get($file)));

    dan_import('katsudon', $file)->assertSuccessful();

    expect(WdbDanCourse::query()->sole()->requirements[1])->toBe(['type' => 'bads', 'red' => [101], 'gold' => [1]]);
});

it('refuses a malformed file and an unknown series, keeping what is there', function (): void {
    dan_import('green', dan_xml())->assertSuccessful();

    dan_import('green', dan_xml(end: '</ExcellentConditions>'))->assertFailed();
    dan_import('nope', dan_xml())->assertFailed();

    expect(WdbDanCourse::query()->count())->toBe(1);
});

it('writes the series file and loads it again without the game file', function (): void {
    dan_import('green', dan_xml())->assertSuccessful();
    $folder = storage_path('framework/testing/dan-data-'.$this->dataFolder);
    expect(json_decode(File::get("{$folder}/green.json"), true)[0]['songs'][0])
        ->toBe(['song_key' => 'umzaso', 'course' => 0, 'notes' => 138]);

    WdbDanCourse::query()->delete();
    $this->artisan('app:import-waddamburo-dan', ['--data' => $folder])->assertSuccessful();

    expect(WdbDanCourse::query()->sole()->songs)->toHaveCount(3);
});

it('changes a course hash only when its songs or requirements change', function (): void {
    $import = fn (string $file) => dan_import('green', $file)->assertSuccessful();
    $hash = fn (): string => WdbDanCourse::query()->sole()->content_hash;

    $import(dan_xml());
    $first = $hash();
    $import(dan_xml());
    expect($hash())->toBe($first);
    $import(dan_xml(goldHits: 440));
    expect($hash())->not->toBe($first);
});
