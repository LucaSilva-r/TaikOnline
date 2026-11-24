<?php

use App\Models\Player;
use App\Models\PlayerShopItem;
use App\Models\User;
use App\Models\WdbChart;
use App\Services\WaddamburoEvents;
use App\Services\WaddamburoSongs;
use App\Services\WaddamburoUnlocks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A stock chart of a Green song (its musicid), as the chart import links them.
function eventChart(string $musicId): string
{
    $chart = WdbChart::query()->create(['sha256' => hash('sha256', $musicId), 'notes' => $musicId, 'source' => 'Stock', 'course' => 3, 'is_stock' => true]);
    $song = DB::table('wdb_songs')->insertGetId(['family' => WaddamburoSongs::family('Stock'), 'song_key' => $musicId, 'title' => $musicId,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('wdb_chart_song')->insert(['wdb_chart_id' => $chart->id, 'wdb_song_id' => $song, 'source' => 'Stock']);

    return $chart->sha256;
}

function playEventSongs(string $token, string $sha256, int $count, string $at): void
{
    test()->withToken($token)->postJson('/api/wdb/medals/songs', ['songs' => array_map(fn (int $i): array => [
        'id' => (string) Str::uuid(), 'played_at' => $at, 'kind' => 'song', 'chart_sha256' => $sha256,
    ], range(1, $count))])->assertOk();
}

it('earns tickets from the running campaign\'s songs and exchanges them for its puchi', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    $token = $user->createToken('events', ['wdb'])->plainTextToken;
    $events = app(WaddamburoEvents::class);
    $touhou = eventChart('thchil');
    $other = eventChart('imsims');

    // The rotation's first run is Touhou: the game's event folder lists its songs.
    $this->travelTo(WaddamburoEvents::EPOCH.' 12:00:00');
    $this->getJson('/api/wdb/event')->assertOk()->assertJsonPath('key', 'touhou')->assertJsonPath('art', 'S10100-1:11')
        ->assertJson(fn ($json) => $json->where('music_ids', fn ($ids) => collect($ids)->contains('thchil'))->etc());

    // Four Touhou plays earn four tickets; another campaign's song earns none.
    playEventSongs($token, $touhou, 4, now()->toIso8601String());
    playEventSongs($token, $other, 3, now()->toIso8601String());
    expect($events->tickets($player->baid, 0))->toBe(4)
        ->and(app(WaddamburoUnlocks::class)->locked($player)['puchi'])->toContain(5);

    // 5 tickets a puchi: refused at 4, exchanged at 5, then owned and no longer locked.
    $this->actingAs($user)->post('/waddamburo/events', ['id' => 5])->assertRedirect();
    expect(PlayerShopItem::query()->where('baid', $player->baid)->count())->toBe(0);
    playEventSongs($token, $touhou, 1, now()->toIso8601String());
    $this->actingAs($user)->post('/waddamburo/events', ['id' => 5])->assertRedirect();
    $this->actingAs($user)->post('/waddamburo/events', ['id' => 5])->assertRedirect();
    expect(PlayerShopItem::query()->where('baid', $player->baid)->where('season_id', WaddamburoEvents::SEASON)->count())->toBe(1)
        ->and($events->tickets($player->baid, 0))->toBe(0)
        ->and(app(WaddamburoUnlocks::class)->locked($player)['puchi'])->not->toContain(5);

    // Another campaign's puchi is not exchanged now; the page shows the run, the tickets and the puchi.
    $this->actingAs($user)->post('/waddamburo/events', ['id' => 12])->assertRedirect();
    expect(PlayerShopItem::query()->where('baid', $player->baid)->count())->toBe(1);
    $this->actingAs($user)->get('/waddamburo/events')->assertOk()
        ->assertInertia(fn ($page) => $page->component('WaddamburoEvents')->where('event.key', 'touhou')->where('tickets', 0)
            ->where('puchi.0.owned', true));

    // Touhou plays after its run earn nothing; its next run (a full rotation later) adds to the same tickets.
    $this->travelTo(now()->addDays(WaddamburoEvents::RUN_DAYS));
    playEventSongs($token, $touhou, 2, now()->toIso8601String());
    expect($events->tickets($player->baid, 0))->toBe(0);
    $this->travelTo(CarbonImmutable::parse(WaddamburoEvents::EPOCH.' 12:00:00')->addDays(WaddamburoEvents::RUN_DAYS * count(WaddamburoEvents::CAMPAIGNS)));
    playEventSongs($token, $touhou, 2, now()->toIso8601String());
    expect($events->tickets($player->baid, 0))->toBe(2);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/musicinfo.xml'), 'needs Green\'s musicinfo.xml');

it('gives a play-to-unlock puchi for playing its song while its campaign runs', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    $token = $user->createToken('events', ['wdb'])->plainTextToken;
    $index = collect(WaddamburoEvents::CAMPAIGNS)->search(fn (array $campaign) => $campaign['key'] === 'crossbeats');
    $overClock = eventChart('ov969');

    // Played before its run: nothing. Played during it: 暁月 (74) is owned; 燦 (75) needs SAKURA EXHAUST.
    $this->travelTo(WaddamburoEvents::EPOCH.' 12:00:00');
    playEventSongs($token, $overClock, 1, now()->toIso8601String());
    expect(app(WaddamburoEvents::class)->owned($player->baid))->toBe([]);
    $this->travelTo(CarbonImmutable::parse(WaddamburoEvents::EPOCH.' 12:00:00')->addDays(WaddamburoEvents::RUN_DAYS * $index));
    playEventSongs($token, $overClock, 1, now()->toIso8601String());
    expect(app(WaddamburoEvents::class)->owned($player->baid))->toBe([74])
        ->and(app(WaddamburoUnlocks::class)->locked($player)['puchi'])->toContain(75)->not->toContain(74);
})->skip(fn () => ! file_exists(config('taiko_green.data_path').'/green/musicinfo.xml'), 'needs Green\'s musicinfo.xml');
