<?php

use App\Enums\PlayReportReason;
use App\Enums\UploadWarningKind;
use App\Http\Controllers\WaddamburoWebController;
use App\Jobs\AnalyzeWaddamburoPlays;
use App\Models\ModerationAction;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use App\Models\WdbUploadWarning;
use App\Notifications\WaddamburoNotice;
use App\Services\ModerationService;
use App\Services\WaddamburoAnalyzer;
use App\Services\WaddamburoSongs;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

/** A chart linked to a song as the given source, as the chart import does. */
function dan_chart(string $songKey, int $course, string $source = 'Stock', bool $stock = true, bool $legacy = false): string
{
    $sha256 = hash('sha256', $songKey.$course.$source.($stock ? 's' : '').($legacy ? 'l' : ''));
    $chart = WdbChart::query()->create(['sha256' => $sha256, 'source' => $source, 'course' => $course, 'is_stock' => $stock,
        'legacy_at' => $legacy ? now() : null]);
    WaddamburoSongs::attach([['wdb_chart_id' => $chart->id, 'source' => $source, 'song_key' => $songKey, 'title' => 'Title '.$songKey]]);

    return $sha256;
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

it('serves official stock charts only, without logging in', function (): void {
    dan_import('green', dan_xml())->assertSuccessful();
    $official = dan_chart('umzaso', 0);
    dan_chart('umzaso', 1); // another course of the song
    dan_chart('umzaso', 0, 'Nijiiro'); // the Nijiiro version
    dan_chart('umzaso', 0, 'Stock', stock: false); // not official
    dan_chart('umzaso', 0, 'Stock', legacy: true); // an old reading of a branched chart

    $series = $this->getJson('api/wdb/dan')->assertOk()->json('series');

    expect($series)->toHaveCount(1)
        ->and($series[0]['id'])->toBe('green')
        ->and($series[0]['courses'][0]['songs'][0])->toMatchArray(['song_key' => 'umzaso', 'course' => 0, 'charts' => [$official]])
        ->and($series[0]['courses'][0]['songs'][1]['charts'])->toBe([]);
});

/** A course with three songs, each with a stock chart the game would send, and a play of it. */
function dan_played_course(): array
{
    $course = WdbDanCourse::query()->create(['series' => 'green', 'level' => 7, 'name' => '五級', 'content_hash' => str_repeat('c', 64),
        'requirements' => [['type' => 'bads', 'red' => [55], 'gold' => [44]]]]);
    $songs = [];
    foreach (['a', 'b', 'c'] as $position => $key) {
        $notes = "notes {$key}";
        $chart = WdbChart::query()->create(['sha256' => hash('sha256', $notes), 'notes' => $notes, 'source' => 'Stock', 'course' => 3, 'is_stock' => true]);
        $song = DB::table('wdb_songs')->insertGetId(['family' => WaddamburoSongs::family('Stock'), 'song_key' => $key, 'title' => $key, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wdb_chart_song')->insert(['wdb_chart_id' => $chart->id, 'wdb_song_id' => $song, 'source' => 'Stock']);
        $course->songs()->create(['position' => $position, 'song_key' => $key, 'course' => 3, 'notes' => 100]);
        $songs[] = ['sha256' => $chart->sha256, 'replay' => base64_encode(gzencode("\x01\x01".chr($position + 1)."\x00"))];
    }

    return [$course, $songs];
}

function dan_play(WdbDanCourse $course, array $songs, array $over = []): array
{
    return [...['id' => (string) Str::uuid(), 'course_hash' => $course->content_hash, 'result_level' => 2, 'score' => 999999, 'great' => 300, 'good' => 0,
        'miss' => 0, 'max_combo' => 300, 'rolls' => 0, 'gauge_percent' => 100, 'songs_played' => 3, 'scoring_version' => 4, 'engine_version' => 'test',
        'played_at' => now()->toIso8601String(), 'songs' => $songs], ...$over];
}

it('keeps the numbers the server scores from a dan play, not the ones sent', function (): void {
    config(['services.waddamburo.scorer' => PHP_BINARY]); // any executable: the process is faked
    $sent = null;
    Process::fake(function (PendingProcess $process) use (&$sent) {
        $sent = json_decode(trim((string) $process->input), true);

        return Process::result(json_encode(['id' => $sent['id'], 'result_level' => 0, 'score' => 1000, 'great' => 10, 'good' => 2, 'miss' => 60,
            'max_combo' => 9, 'rolls' => 3, 'gauge_percent' => 51, 'songs_played' => 2, 'scoring_version' => 4,
            'songs' => [['great' => 5, 'good' => 1, 'miss' => 50, 'rolls' => 1], ['great' => 5, 'good' => 1, 'miss' => 10, 'rolls' => 2], ['great' => 0, 'good' => 0, 'miss' => 0, 'rolls' => 0]]]));
    });
    [$course, $songs] = dan_played_course();
    $player = wdb_player();
    $play = dan_play($course, $songs);

    $this->withToken(wdb_token($player))->postJson('/api/wdb/dan/plays', ['plays' => [$play]])
        ->assertOk()->assertJson(['accepted' => [$play['id']]]);

    $stored = WdbDanPlay::query()->with('songs')->findOrFail($play['id']);
    expect($stored->result_level)->toBe(0)
        ->and($stored->score)->toBe(1000)
        ->and($stored->songs_played)->toBe(2)
        ->and($stored->rescored_at)->not->toBeNull()
        ->and($stored->songs->pluck('miss')->all())->toBe([50, 10, 0])
        ->and($sent['requirements'][0]['type'])->toBe('bads')
        ->and($sent['songs'][1]['note_count'])->toBe(100)
        ->and(gzdecode(base64_decode($sent['songs'][1]['replay'])))->toBe("\x01\x01\x02\x00");
});

it('refuses a dan play with a malformed replay and tells the player, and still takes a song not reached', function (): void {
    Notification::fake();
    [$course, $songs] = dan_played_course();
    $player = wdb_player();
    $songs[2]['replay'] = base64_encode(gzencode("\x01\x05"));
    $malformed = dan_play($course, $songs);
    $songs[2]['replay'] = null;
    $unfinished = dan_play($course, $songs);

    $this->withToken(wdb_token($player))->postJson('/api/wdb/dan/plays', ['plays' => [$malformed, $unfinished]])
        ->assertOk()->assertExactJson(['accepted' => [$unfinished['id']]]);

    expect(WdbDanPlay::query()->pluck('id')->all())->toBe([$unfinished['id']]);
    Notification::assertSentTo($player->user, WaddamburoNotice::class,
        fn (WaddamburoNotice $notice): bool => $notice->kind === 'play_rejected' && str_contains($notice->message, 'dan run'));
});

it('refuses a dan play on a chart that is not the course\'s', function (): void {
    [$course, $songs] = dan_played_course();
    $songs[1]['sha256'] = str_repeat('f', 64);
    $play = dan_play($course, $songs);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/dan/plays', ['plays' => [$play]])
        ->assertOk()->assertJson(['accepted' => []]);

    expect(WdbDanPlay::query()->count())->toBe(0);
});

it('keeps a dan play uncounted until it is scored', function (): void {
    config(['services.waddamburo.scorer' => '']);
    [$course, $songs] = dan_played_course();
    $play = dan_play($course, $songs);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/dan/plays', ['plays' => [$play]])->assertOk();

    expect(WdbDanPlay::query()->findOrFail($play['id'])->rescored_at)->toBeNull();
});

it('shows every series on the board, with ranks and challenges apart', function (): void {
    [$course] = dan_played_course();
    WdbDanCourse::query()->create(['series' => 'green', 'level' => 101, 'name' => '復活！カツドン大将', 'content_hash' => str_repeat('d', 64), 'requirements' => []]);
    WdbDanCourse::query()->create(['series' => 'red', 'level' => 1, 'name' => '初級', 'content_hash' => str_repeat('e', 64), 'requirements' => []]);
    $player = wdb_player();
    $scored = ['baid' => $player->baid, 'score' => 1, 'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0, 'gauge_percent' => 100,
        'songs_played' => 3, 'scoring_version' => 4, 'engine_version' => '', 'played_at' => now()];
    WdbDanPlay::query()->create([...$scored, 'content_hash' => $course->content_hash, 'result_level' => 2, 'rescored_at' => now()]);
    WdbDanPlay::query()->create([...$scored, 'content_hash' => str_repeat('d', 64), 'result_level' => 2]); // not scored yet

    $progress = (new ReflectionMethod(WaddamburoWebController::class, 'danProgress'))
        ->invoke(app(WaddamburoWebController::class), $player);

    $green = collect($progress)->firstWhere('id', 'green');
    expect(collect($progress)->pluck('id')->all())->toBe(['green', 'red'])
        ->and($green['ranks_total'])->toBe(1)
        ->and($green['ranks_gold'])->toBe(1)
        ->and($green['top_rank'])->toBe('5 Kyu')
        ->and($green['challenges_total'])->toBe(1)
        ->and($green['challenges_passed'])->toBe(0);
});

it('translates every challenge course name', function (string $name, string $title): void {
    expect(WdbDanCourse::challengeTitle($name))->toBe($title);
})->with([
    ['復活！カツドン大将', 'Revival: Katsu-Don Taisho'],
    ['復活！モモイロ十段', 'Revival: Momoiro 10 Dan'],
    ['チャレンジ！グリーン外伝③', 'Green Gaiden 3'],
    ['挑戦！３３４万点！！', '3.34 Million Points'],
    ['挑戦！ドンダフルコンボ！！', 'Donderful Combo'],
    ['太鼓チームからの挑戦！２', 'Taiko Team Challenge 2'],
    ['太鼓チームからの挑戦！①', 'Taiko Team Challenge 1'],
    ['タトイ云－一ノ十②', 'Secret Gaiden 2'],
    ['挑戦！ナムコオリジナル３', 'Namco Original 3'],
    ['挑戦！３５００コンボ！！', '3500 Combo'],
    ['挑戦！第３回天下一音ゲ祭の巻【裏】', '3rd Tenkaichi Otogesai (Ura)'],
    ['挑戦！世界大会2016エリア②', 'World Tournament 2016 Area 2'],
    ['挑戦！世界大会2016決勝', 'World Tournament 2016 Final'],
    ['挑戦！世界大会2016ＥＸ①', 'World Tournament 2016 EX 1'],
    ['挑戦！１４００連打！！', '1400 Rolls'],
]);

it('leaves no Japanese in any challenge title', function (): void {
    $names = collect(File::glob(database_path('data/dan/*.json')))
        ->flatMap(fn (string $file) => collect(json_decode(File::get($file), true))->where('level', '>', 25)->pluck('name'))->unique();

    expect($names)->not->toBeEmpty();
    foreach ($names as $name) {
        expect(WdbDanCourse::challengeTitle($name))->not->toMatch('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}！]/u');
    }
});

it('shows a dan run to anyone, except one staff hid', function (): void {
    [$course, $songs] = dan_played_course();
    $player = wdb_player();
    $play = WdbDanPlay::query()->create(['baid' => $player->baid, 'content_hash' => $course->content_hash, 'result_level' => 1, 'score' => 1, 'great' => 1, 'good' => 0, 'miss' => 0,
        'max_combo' => 1, 'rolls' => 0, 'gauge_percent' => 100, 'songs_played' => 3, 'scoring_version' => 4, 'engine_version' => '', 'played_at' => now(), 'rescored_at' => now()]);

    $this->get("/waddamburo/dan-plays/{$play->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('WaddamburoDanPlay')->where('course.label', '5 Kyu')->where('play.result_level', 1));

    $play->update(['hidden_at' => now()]);
    $this->get("/waddamburo/dan-plays/{$play->id}")->assertNotFound();
});

it('files one report per player on a dan run, and none on their own', function (): void {
    Notification::fake();
    [$course] = dan_played_course();
    $player = wdb_player();
    $play = WdbDanPlay::query()->create(['baid' => $player->baid, 'content_hash' => $course->content_hash, 'result_level' => 1, 'score' => 1, 'great' => 1, 'good' => 0, 'miss' => 0,
        'max_combo' => 1, 'rolls' => 0, 'gauge_percent' => 100, 'songs_played' => 3, 'scoring_version' => 4, 'engine_version' => '', 'played_at' => now(), 'rescored_at' => now()]);
    $moderation = app(ModerationService::class);
    $reporter = wdb_player('30800000000000000002')->user;

    expect($moderation->reportDan($reporter, $play, PlayReportReason::cases()[0], null))->toBeTrue()
        ->and($moderation->reportDan($reporter, $play, PlayReportReason::cases()[0], null))->toBeFalse();
    expect(fn () => $moderation->reportDan($player->user, $play, PlayReportReason::cases()[0], null))
        ->toThrow(HttpException::class);
});

it('logs what staff do to a dan run, naming the run', function (): void {
    [$course] = dan_played_course();
    $player = wdb_player();
    $play = WdbDanPlay::query()->create(['baid' => $player->baid, 'content_hash' => $course->content_hash, 'result_level' => 1, 'score' => 1, 'great' => 1, 'good' => 0, 'miss' => 0,
        'max_combo' => 1, 'rolls' => 0, 'gauge_percent' => 100, 'songs_played' => 3, 'scoring_version' => 4, 'engine_version' => '', 'played_at' => now(), 'rescored_at' => now()]);
    $moderator = wdb_player('30800000000000000002')->user;

    app(ModerationService::class)->hideDan($moderator, $play, 'cheated');

    expect($play->fresh()->hidden_reason)->toBe('cheated')
        ->and(ModerationAction::query()->where('action', 'hide_dan_play')->value('wdb_dan_play_id'))->toBe($play->id);
});

it('accepts a failed dan run without keeping it', function (): void {
    [$course, $songs] = dan_played_course();
    $failed = dan_play($course, $songs, ['result_level' => 0]);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/dan/plays', ['plays' => [$failed]])
        ->assertSuccessful()->assertJsonPath('accepted', [$failed['id']]);

    expect(WdbDanPlay::query()->count())->toBe(0);
});

it('culls failed plays and runs, but keeps those staff hid or players reported', function (): void {
    $player = wdb_player();
    $run = fn (int $level, array $over = []) => WdbDanPlay::query()->create(['baid' => $player->baid, 'content_hash' => str_repeat('e', 64), 'result_level' => $level,
        'score' => 1, 'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0, 'gauge_percent' => 1, 'songs_played' => 1, 'scoring_version' => 4,
        'engine_version' => 'test', 'played_at' => now(), ...$over]);
    $passed = $run(1);
    $failed = $run(0);
    $hidden = $run(0, ['hidden_at' => now(), 'hidden_reason' => 'evidence']);

    $this->artisan('app:cull-failed-waddamburo-plays --dry-run')->assertSuccessful();
    expect(WdbDanPlay::query()->count())->toBe(3);

    $this->artisan('app:cull-failed-waddamburo-plays')->assertSuccessful();
    expect(WdbDanPlay::query()->pluck('id')->sort()->values()->all())->toBe(collect([$passed->id, $hidden->id])->sort()->values()->all());
});

it('lists a player\'s dan runs on their moderation page', function (): void {
    [$course] = dan_played_course();
    $player = wdb_player();
    $run = WdbDanPlay::query()->create(['baid' => $player->baid, 'content_hash' => $course->content_hash, 'result_level' => 1,
        'score' => 1, 'great' => 1, 'good' => 0, 'miss' => 0, 'max_combo' => 1, 'rolls' => 0, 'gauge_percent' => 1, 'songs_played' => 3, 'scoring_version' => 4,
        'engine_version' => 'test', 'played_at' => now()]);

    $this->actingAs(User::factory()->moderator()->create())->get("/waddamburo/mod/players/{$player->baid}")->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('mod/Player')->where('danRuns.0.id', $run->id)->where('danRuns.0.result_level', 1));
});

it('hides a disabled series from the catalogue and refuses its plays until an admin enables it', function (): void {
    WdbDanCourse::query()->create(['series' => 'green', 'level' => 7, 'name' => '五級', 'requirements' => [], 'content_hash' => str_repeat('a', 64)]);
    WdbDanCourse::query()->create(['series' => 'nijiiro-2025', 'level' => 7, 'name' => '五級', 'requirements' => [], 'content_hash' => str_repeat('b', 64)]);
    $admin = User::factory()->withTwoFactor()->create(['role' => 'admin']);
    $served = fn () => collect($this->getJson('/api/wdb/dan')->assertOk()->json('series'))->pluck('id')->all();

    expect(WdbDanCourse::disabledSeries())->toContain('nijiiro-2025')->and($served())->toBe(['green']);

    $this->actingAs($admin)->patch('/waddamburo/admin/waddamburo-dan/nijiiro-2025', ['enabled' => true])->assertRedirect();
    expect($served())->toBe(['nijiiro-2025', 'green']);
    $this->patch('/waddamburo/admin/waddamburo-dan/green', ['enabled' => false])->assertRedirect();
    expect($served())->toBe(['nijiiro-2025']);

    $this->patch('/waddamburo/admin/waddamburo-dan/nonsense', ['enabled' => false])->assertNotFound();
    $this->actingAs(User::factory()->create())->patch('/waddamburo/admin/waddamburo-dan/green', ['enabled' => true])->assertForbidden();
});

it('shows admins which dan songs have official charts', function (): void {
    $course = WdbDanCourse::query()->create(['series' => 'green', 'level' => 7, 'name' => '五級', 'requirements' => [], 'content_hash' => str_repeat('a', 64)]);
    $course->songs()->createMany([
        ['position' => 0, 'song_key' => 'official', 'course' => 3, 'notes' => 100],
        ['position' => 1, 'song_key' => 'modded', 'course' => 3, 'notes' => 100],
        ['position' => 2, 'song_key' => 'nowhere', 'course' => 3, 'notes' => 100],
    ]);
    $link = fn (string $song, bool $stock, string $sha) => WaddamburoSongs::attach([['wdb_chart_id' => WdbChart::query()->create(['sha256' => str_repeat($sha, 64), 'title' => $song, 'source' => 'Stock', 'course' => 3, 'is_stock' => $stock])->id, 'song_key' => $song, 'source' => 'Stock', 'title' => $song]]);
    $link('official', true, 'c');
    $link('modded', false, 'd');

    $this->actingAs(User::factory()->withTwoFactor()->create(['role' => 'admin']))->get('/waddamburo/admin/waddamburo-dan')
        ->assertInertia(fn (Assert $page) => $page->component('admin/WaddamburoDan')
            ->where('series.0.id', 'green')->where('series.0.enabled', true)->where('series.0.open', 0)
            ->where('series.0.courses.0.songs.0.status', 'official')
            ->where('series.0.courses.0.songs.1.status', 'custom')
            ->where('series.0.courses.0.songs.2.status', 'none'));
});

it('analyzes a dan run\'s reached songs, stores its flag and warns moderators, not the player', function (): void {
    Notification::fake();
    config(['services.waddamburo.analyzer' => PHP_BINARY]); // any executable: the process is faked
    $sent = null;
    Process::fake(function (PendingProcess $process) use (&$sent) {
        $sent = json_decode(trim((string) $process->input), true);

        return Process::result(json_encode(['id' => $sent['id'], 'flag' => 'strong', 'analysis' => ['private' => 1]]));
    });
    [$course, $songs] = dan_played_course();
    $player = wdb_player();
    $play = dan_play($course, $songs);
    $this->withToken(wdb_token($player))->postJson('/api/wdb/dan/plays', ['plays' => [$play]])->assertOk();
    $run = WdbDanPlay::query()->with('songs')->sole();
    $run->songs[0]->update(['timeline' => gzencode('{}')]);
    $run->songs[1]->update(['timeline' => gzencode('{}')]);

    (new AnalyzeWaddamburoPlays([$run->id], true))->handle(app(WaddamburoAnalyzer::class));
    (new AnalyzeWaddamburoPlays([$run->id], true))->handle(app(WaddamburoAnalyzer::class));

    expect(array_column($sent['songs'], 'position'))->toBe([0, 1])
        ->and($run->fresh())->analysis_flag->toBe('strong')->analysis->toBe(['private' => 1])->analyzed_at->not->toBeNull()
        ->and(WdbUploadWarning::query()->sole())->kind->toBe(UploadWarningKind::AnalyzerFlag)->count->toBe(1)->play_id->toBe($run->id);
    Notification::assertNotSentTo($player->user, WaddamburoNotice::class);
});

it('queues a dan run for the analyzer once it is scored', function (): void {
    Queue::fake();
    config(['services.waddamburo.scorer' => PHP_BINARY, 'services.waddamburo.analyzer' => PHP_BINARY]);
    Process::fake(fn (PendingProcess $process) => Process::result(json_encode(['id' => json_decode(trim((string) $process->input), true)['id'], 'result_level' => 1, 'score' => 1000, 'great' => 10, 'good' => 2, 'miss' => 0,
        'max_combo' => 9, 'rolls' => 3, 'gauge_percent' => 80, 'songs_played' => 3, 'scoring_version' => 4])));
    [$course, $songs] = dan_played_course();
    $play = dan_play($course, $songs);

    $this->withToken(wdb_token(wdb_player()))->postJson('/api/wdb/dan/plays', ['plays' => [$play]])->assertOk();

    Queue::assertPushed(AnalyzeWaddamburoPlays::class, fn (AnalyzeWaddamburoPlays $job): bool => $job->danRuns && $job->playIds === [$play['id']]);
});

it('shows a dan run\'s flag to staff and its analysis to admins only', function (): void {
    [$course, $songs] = dan_played_course();
    $player = wdb_player();
    $play = dan_play($course, $songs);
    $this->withToken(wdb_token($player))->postJson('/api/wdb/dan/plays', ['plays' => [$play]])->assertOk();
    $run = WdbDanPlay::query()->sole();
    $run->update(['analysis_flag' => 'review', 'analysis' => ['private' => 1]]);
    $url = "/waddamburo/dan-plays/{$run->id}";
    app('auth')->forgetGuards(); // the previous request's user stays set inside one test

    $this->actingAs(User::factory()->admin()->create())->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('moderation.analysis_flag', 'review')->where('moderation.analysis', ['private' => 1]));
    $this->actingAs(User::factory()->moderator()->create())->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('moderation.analysis_flag', 'review')->where('moderation.analysis', null));
    $this->actingAs($player->user)->get($url)->assertInertia(fn (Assert $page) => $page->where('moderation', null));
});
