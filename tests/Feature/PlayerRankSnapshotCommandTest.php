<?php

use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Models\User;

it('records daily rank snapshots for a selected version in deterministic order', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 11)->setTime(12, 0));

    $topUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $emptyUser = User::factory()->create();

    $topPlayer = Player::query()->create(['mydon_name' => 'TOP', 'user_id' => $topUser->id]);
    $secondPlayer = Player::query()->create(['mydon_name' => 'SECOND', 'user_id' => $secondUser->id]);
    Player::query()->create(['mydon_name' => 'EMPTY', 'user_id' => $emptyUser->id]);

    SongBest::query()->create([
        'baid' => $topPlayer->baid,
        'game_version' => 'green',
        'song_no' => 1,
        'level' => 4,
        'best_score' => 1000,
        'best_score_rank' => 8,
        'best_play_result' => 3,
        'best_crown' => 3,
    ]);
    SongBest::query()->create([
        'baid' => $secondPlayer->baid,
        'game_version' => 'green',
        'song_no' => 1,
        'level' => 4,
        'best_score' => 700,
        'best_score_rank' => 7,
        'best_play_result' => 2,
        'best_crown' => 2,
    ]);
    SongBest::query()->create([
        'baid' => $secondPlayer->baid,
        'game_version' => 'blue',
        'song_no' => 1,
        'level' => 4,
        'best_score' => 999999,
        'best_score_rank' => 10,
        'best_play_result' => 3,
        'best_crown' => 3,
    ]);

    SongPlayResult::query()->create([
        'baid' => $topPlayer->baid,
        'game_version' => 'green',
        'song_no' => 1,
        'level' => 4,
        'score' => 1000,
    ]);
    SongPlayResult::query()->create([
        'baid' => $secondPlayer->baid,
        'game_version' => 'green',
        'song_no' => 1,
        'level' => 4,
        'score' => 700,
    ]);

    $this->artisan('app:record-player-rank-snapshots green')
        ->assertSuccessful();

    // Players with no scores for the version are not ranked, so they get no
    // snapshot (the empty player is excluded).
    expect(PlayerRankSnapshot::query()->count())->toBe(2);

    $topSnapshot = PlayerRankSnapshot::query()
        ->whereBelongsTo($topUser)
        ->where('game_version', 'green')
        ->firstOrFail();
    $secondSnapshot = PlayerRankSnapshot::query()
        ->whereBelongsTo($secondUser)
        ->where('game_version', 'green')
        ->firstOrFail();

    expect(PlayerRankSnapshot::query()->whereBelongsTo($emptyUser)->exists())->toBeFalse();

    expect($topSnapshot->rank)->toBe(1)
        ->and($topSnapshot->total_score)->toBe(1000)
        ->and($topSnapshot->played_song_count)->toBe(1)
        ->and($topSnapshot->crown_counts['dondaful'])->toBe(1)
        ->and($secondSnapshot->rank)->toBe(2)
        ->and($secondSnapshot->total_score)->toBe(700)
        ->and($secondSnapshot->crown_counts['gold'])->toBe(1);

    $this->artisan('app:record-player-rank-snapshots green')
        ->assertSuccessful();

    expect(PlayerRankSnapshot::query()->count())->toBe(2);
});

it('fails when the requested game version is unknown', function (): void {
    $this->artisan('app:record-player-rank-snapshots nope')
        ->assertFailed();
});
