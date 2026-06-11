<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\User;

it('shows rankings for the selected version only', function (): void {
    Song::query()->create([
        'version' => 'blue',
        'song_no' => 2,
        'music_id' => 'blue-shared',
        'unique_id' => 2,
        'title' => 'Shared Song',
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);

    Song::query()->create([
        'version' => 'green',
        'song_no' => 20,
        'music_id' => 'green-shared',
        'unique_id' => 20,
        'title' => 'Shared Song',
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);

    $blueUser = User::factory()->create(['name' => 'Blue Account']);
    $greenUser = User::factory()->create(['name' => 'Green Account']);

    $bluePlayer = Player::query()->create(['mydon_name' => 'BLUE', 'user_id' => $blueUser->id]);
    $greenPlayer = Player::query()->create(['mydon_name' => 'GREEN', 'user_id' => $greenUser->id]);
    $guestPlayer = Player::query()->create(['mydon_name' => 'GUEST']);

    SongBest::query()->create([
        'baid' => $bluePlayer->baid,
        'game_version' => 'blue',
        'song_no' => 2,
        'level' => 3,
        'best_score' => 900000,
        'best_score_rank' => 6,
        'best_play_result' => 2,
    ]);

    SongBest::query()->create([
        'baid' => $greenPlayer->baid,
        'game_version' => 'green',
        'song_no' => 20,
        'level' => 3,
        'best_score' => 880000,
        'best_score_rank' => 6,
        'best_play_result' => 2,
    ]);

    SongBest::query()->create([
        'baid' => $guestPlayer->baid,
        'game_version' => 'green',
        'song_no' => 20,
        'level' => 3,
        'best_score' => 999999,
        'best_score_rank' => 8,
        'best_play_result' => 2,
    ]);

    $this->get('/green/rankings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rankings')
            ->has('songGroups', 1)
            ->where('songGroups.0.title', 'Shared Song')
            ->has('songGroups.0.versions', 1)
            ->where('songGroups.0.versions.0.game_version', 'green')
            ->where('songGroups.0.versions.0.entries.0.player_name', 'Green Account')
            ->missing('songGroups.0.versions.0.entries.1')
        );
});

it('does not expose all-version rankings publicly', function (): void {
    $this->get('/all/rankings')->assertNotFound();
});
