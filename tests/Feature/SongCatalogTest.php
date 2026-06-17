<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Models\User;
use App\Support\SongSearch;

function makeCatalogSong(string $version, int $songNo, string $title): Song
{
    return Song::query()->create([
        'version' => $version,
        'song_no' => $songNo,
        'music_id' => "{$version}-{$songNo}",
        'unique_id' => $songNo,
        'title' => $title,
        'search_index' => SongSearch::indexFor($title),
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);
}

it('lists songs for the selected version with play counts', function (): void {
    makeCatalogSong('green', 10, 'Green Song');
    makeCatalogSong('blue', 11, 'Blue Song');

    $player = Player::query()->create([
        'mydon_name' => 'P',
        'user_id' => User::factory()->create()->id,
    ]);

    SongPlayResult::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 10,
        'level' => 4,
        'play_result' => 2,
        'score' => 800000,
        'score_rank' => 6,
        'played_at' => now(),
    ]);

    $this->get('/green/songs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Songs')
            ->where('gameVersion.value', 'green')
            ->has('songs.data', 1)
            ->where('songs.data.0.title', 'Green Song')
            ->where('songs.data.0.play_count', 1)
            ->where('songs.data.0.player_count', 1)
        );
});

it('filters songs by search query', function (): void {
    makeCatalogSong('green', 10, 'Alpha');
    makeCatalogSong('green', 11, 'Beta');

    $this->get('/green/songs?q=Bet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('songs.data', 1)
            ->where('songs.data.0.title', 'Beta')
            ->where('filters.q', 'Bet')
        );
});

it('finds japanese songs by romaji and fullwidth latin titles', function (): void {
    makeCatalogSong('green', 10, 'にんじゃりばんばん');
    makeCatalogSong('green', 11, 'Ｄａｎｃｅ');

    $this->get('/green/songs?q=ninja')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('songs.data', 1)
            ->where('songs.data.0.title', 'にんじゃりばんばん')
        );

    $this->get('/green/songs?q=dance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('songs.data', 1)
            ->where('songs.data.0.title', 'Ｄａｎｃｅ')
        );
});

it('computes precision from the best play in the leaderboard and recent plays', function (): void {
    $song = makeCatalogSong('green', 10, 'Precision Song');
    $user = User::factory()->create(['name' => 'Acc Player']);
    $player = Player::query()->create(['mydon_name' => 'P', 'user_id' => $user->id]);

    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 10,
        'level' => 4,
        'best_score' => 950000,
        'best_score_rank' => 7,
        'best_crown' => 2,
        'best_play_result' => 2,
    ]);

    // 3 good, 1 ok, 0 miss => (3 + 0.5) / 4 = 87.5%.
    SongPlayResult::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 10,
        'level' => 4,
        'play_result' => 2,
        'score' => 950000,
        'score_rank' => 7,
        'good_count' => 3,
        'ok_count' => 1,
        'miss_count' => 0,
        'played_at' => now(),
    ]);

    $this->get("/green/songs/{$song->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('difficulties.0.entries.0.precision', 87.5)
            ->where('recentPlays.0.precision', 87.5)
        );
});

it('shows per-difficulty leaderboards for a song, excluding guests', function (): void {
    $song = makeCatalogSong('green', 10, 'Green Song');

    $user = User::factory()->create(['name' => 'Ranked Player']);
    $player = Player::query()->create(['mydon_name' => 'P', 'user_id' => $user->id]);
    $guest = Player::query()->create(['mydon_name' => 'G']);

    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 10,
        'level' => 4,
        'best_score' => 950000,
        'best_score_rank' => 7,
        'best_crown' => 2,
        'best_play_result' => 2,
    ]);

    // Guest has no linked user, so must be excluded from the leaderboard.
    SongBest::query()->create([
        'baid' => $guest->baid,
        'game_version' => 'green',
        'song_no' => 10,
        'level' => 4,
        'best_score' => 999999,
        'best_score_rank' => 8,
        'best_crown' => 3,
        'best_play_result' => 2,
    ]);

    $this->get("/green/songs/{$song->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('SongDetail')
            ->where('song.title', 'Green Song')
            ->has('difficulties', 1)
            ->where('difficulties.0.level', 4)
            ->where('difficulties.0.player_count', 1)
            ->where('difficulties.0.crown_counts.gold', 1)
            ->has('difficulties.0.entries', 1)
            ->where('difficulties.0.entries.0.player_name', 'Ranked Player')
            ->where('difficulties.0.entries.0.score', 950000)
        );
});

it('redirects to the equivalent song when switching to a version that has it', function (): void {
    $green = makeCatalogSong('green', 10, 'Shared Song');
    $blue = makeCatalogSong('blue', 11, 'Shared Song');
    // Same song across versions is matched by unique_id.
    $blue->update(['unique_id' => $green->unique_id]);

    $this->get("/blue/songs/{$green->id}")
        ->assertRedirect("/blue/songs/{$blue->id}");
});

it('redirects to the catalog when the song is missing in the target version', function (): void {
    $green = makeCatalogSong('green', 10, 'Green Only');

    $this->get("/blue/songs/{$green->id}")
        ->assertRedirect('/blue/songs');
});

it('redirects to the catalog when the song does not exist at all', function (): void {
    $this->get('/green/songs/999999')
        ->assertRedirect('/green/songs');
});

it('does not expose the all-version catalog', function (): void {
    $this->get('/all/songs')->assertNotFound();
});
