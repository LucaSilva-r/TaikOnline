<?php

use App\Models\DanCourse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('prefers the matching board config over a stale base medley', function (
    string $version,
    string $colour,
    string $board,
): void {
    $root = storage_path('framework/testing/dan-import-'.str()->uuid());
    $sourceData = "{$root}/source/{$colour}/SCEEX001/USRDIR/data";
    $stagedData = "{$root}/staged";
    File::ensureDirectoryExists("{$sourceData}/config/{$board}");

    $xml = static fn (int $songNo, int $difficulty): string => <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <boost_serialization>
          <MusicMedleyInfoData>
            <uniqueid>20001</uniqueid>
            <medleyname>Dan 1</medleyname>
            <difficulty>3</difficulty>
            <challengelv>1</challengelv>
            <Content>
              <musicid>song{$songNo}</musicid>
              <uniqueid>{$songNo}</uniqueid>
              <difficulty>{$difficulty}</difficulty>
              <notes>100</notes>
            </Content>
          </MusicMedleyInfoData>
        </boost_serialization>
        XML;

    try {
        File::put("{$sourceData}/musicmedleyinfo.xml", $xml(100, 0));
        File::put("{$sourceData}/config/{$board}/musicmedleyinfo.xml", $xml(900, 4));
        config()->set('taiko_green.data_path', $stagedData);

        $this->artisan('app:import-dan-courses', [
            'version' => $version,
            '--source' => "{$root}/source",
        ])->assertSuccessful();

        $course = DanCourse::query()
            ->with('songs')
            ->where('version', $version)
            ->where('dan', 1)
            ->sole();

        expect($course->songs)->toHaveCount(1)
            ->and((int) $course->songs[0]->song_no)->toBe(900)
            // XML is zero-based; the cabinet protocol enum is one-based.
            ->and((int) $course->songs[0]->level)->toBe(5)
            ->and((int) $course->verup_no)->toBeGreaterThan(1)
            ->and(File::get("{$stagedData}/{$version}/musicmedleyinfo.xml"))
            ->toBe($xml(900, 4));
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'green' => ['green', 'GREEN', 'S11100-1'],
    'blue' => ['blue', 'BLUE', 'S10100-1'],
]);
