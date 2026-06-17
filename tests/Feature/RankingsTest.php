<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\User;

function makeRankingSong(string $version, int $songNo): void
{
    Song::query()->create([
        'version' => $version,
        'song_no' => $songNo,
        'music_id' => "{$version}-{$songNo}",
        'unique_id' => $songNo,
        'title' => 'Shared Song',
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);
}

it('shows the global player leaderboard for the selected version only', function (): void {
    makeRankingSong('blue', 2);
    makeRankingSong('green', 20);

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
        'best_crown' => 3,
        'best_play_result' => 2,
    ]);

    SongBest::query()->create([
        'baid' => $greenPlayer->baid,
        'game_version' => 'green',
        'song_no' => 20,
        'level' => 3,
        'best_score' => 880000,
        'best_score_rank' => 6,
        'best_crown' => 2,
        'best_play_result' => 2,
    ]);

    // Guest player has no linked user account, so must be excluded.
    SongBest::query()->create([
        'baid' => $guestPlayer->baid,
        'game_version' => 'green',
        'song_no' => 20,
        'level' => 3,
        'best_score' => 999999,
        'best_score_rank' => 8,
        'best_crown' => 3,
        'best_play_result' => 2,
    ]);

    $this->get('/green/rankings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rankings')
            ->where('gameVersion.value', 'green')
            ->has('entries', 1)
            ->where('entries.0.player_name', 'Green Account')
            ->where('entries.0.rank', 1)
            ->where('entries.0.ranked_song_count', 1)
            ->where('entries.0.crown_counts.gold', 1)
            ->where('entries.0.rank_change', null)
            ->missing('entries.1')
        );
});

it('reports rank movement against the latest prior snapshot', function (): void {
    makeRankingSong('green', 20);

    $user = User::factory()->create(['name' => 'Mover']);
    $player = Player::query()->create(['mydon_name' => 'MOVER', 'user_id' => $user->id]);

    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 20,
        'level' => 3,
        'best_score' => 880000,
        'best_score_rank' => 6,
        'best_crown' => 1,
        'best_play_result' => 2,
    ]);

    PlayerRankSnapshot::query()->create([
        'user_id' => $user->id,
        'game_version' => 'green',
        'rank' => 3,
        'snapshot_date' => today()->subDay(),
    ]);

    $this->get('/green/rankings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entries.0.player_name', 'Mover')
            ->where('entries.0.rank', 1)
            ->where('entries.0.rank_change', 2)
        );
});

it('does not expose all-version rankings publicly', function (): void {
    $this->get('/all/rankings')->assertNotFound();
});
