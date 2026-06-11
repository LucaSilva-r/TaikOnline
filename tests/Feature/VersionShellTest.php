<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongPlayResult;
use App\Models\User;

function create_version_shell_song(string $version, int $songNo): Song
{
    return Song::query()->create([
        'version' => $version,
        'song_no' => $songNo,
        'music_id' => "{$version}-{$songNo}",
        'unique_id' => $songNo,
        'title' => "{$version} Song {$songNo}",
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);
}

test('root redirects to the default version shell', function (): void {
    $this->get('/')->assertRedirect('/green');
});

test('old unversioned browser routes no longer render', function (string $path): void {
    $this->get($path)->assertNotFound();
})->with([
    '/rankings',
    '/community',
    '/admin/recent-plays',
    '/settings/profile',
]);

test('all scope is admin only', function (): void {
    $admin = User::factory()->admin()->create();

    $this->get('/all/rankings')->assertNotFound();

    $this->actingAs($admin)
        ->get('/all/settings/profile')
        ->assertNotFound();
});

test('admin song catalog supports all and version scopes', function (): void {
    $admin = User::factory()->admin()->create();
    create_version_shell_song('green', 1);
    create_version_shell_song('blue', 2);

    $this->actingAs($admin)
        ->get('/all/admin/songs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('taikoVersion.isAll', true)
            ->where('taikoVersion.allowAll', true)
            ->has('songs.data', 2));

    $this->actingAs($admin)
        ->get('/green/admin/songs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('taikoVersion.isAll', false)
            ->where('taikoVersion.scope', 'green')
            ->has('songs.data', 1)
            ->where('songs.data.0.version', 'green'));
});

test('admin recent plays supports all and version scopes', function (): void {
    $admin = User::factory()->admin()->create();
    $player = Player::query()->create(['mydon_name' => 'DON']);

    SongPlayResult::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 1,
        'level' => 3,
        'score' => 900000,
        'score_rank' => 6,
    ]);

    SongPlayResult::query()->create([
        'baid' => $player->baid,
        'game_version' => 'blue',
        'song_no' => 2,
        'level' => 3,
        'score' => 800000,
        'score_rank' => 5,
    ]);

    $this->actingAs($admin)
        ->get('/all/admin/recent-plays')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('results.data', 2));

    $this->actingAs($admin)
        ->get('/green/admin/recent-plays')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.game_version', 'green'));
});
