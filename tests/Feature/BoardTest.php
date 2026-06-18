<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Models\PlayerBlueBattleNpcState;
use App\Models\PlayerDanProgress;
use App\Models\PlayerBlueBattleState;
use App\Models\PlayerBlueBattleTokenState;
use App\Models\PlayerGreenGhostState;
use App\Models\PlayerGreenGhostToken;
use App\Models\PlayerGreenGhostWinnings;
use App\Models\PlayerRankSnapshot;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Models\User;

it('shows a public version-scoped player board without sensitive player identifiers', function (): void {
    $user = User::factory()->create(['name' => 'Firestorm7893']);
    $otherUser = User::factory()->create(['name' => 'Higher Rank']);

    $player = Player::query()->create([
        'mydon_name' => 'DON',
        'user_id' => $user->id,
        'last_played_at' => now()->subDay(),
        'total_credit_count' => 25,
        'total_get_donmedal' => 100,
        'total_use_donmedal' => 30,
        'total_get_katsumedal' => 20,
        'total_use_katsumedal' => 5,
    ]);
    $otherPlayer = Player::query()->create(['mydon_name' => 'TOP', 'user_id' => $otherUser->id]);

    createBoardSong('green', 20, 'Green Song');
    createBoardSong('green', 21, 'Second Song');
    createBoardSong('blue', 20, 'Blue Song');

    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 20,
        'level' => 4,
        'best_score' => 900,
        'best_score_rank' => 8,
        'best_play_result' => 3,
        'best_crown' => 3,
    ]);
    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'song_no' => 21,
        'level' => 3,
        'best_score' => 800,
        'best_score_rank' => 7,
        'best_play_result' => 2,
        'best_crown' => 2,
    ]);
    SongBest::query()->create([
        'baid' => $player->baid,
        'game_version' => 'blue',
        'song_no' => 20,
        'level' => 4,
        'best_score' => 999999,
        'best_score_rank' => 10,
        'best_play_result' => 3,
        'best_crown' => 3,
    ]);
    SongBest::query()->create([
        'baid' => $otherPlayer->baid,
        'game_version' => 'green',
        'song_no' => 20,
        'level' => 4,
        'best_score' => 1000,
        'best_score_rank' => 8,
        'best_play_result' => 3,
        'best_crown' => 3,
    ]);
    SongBest::query()->create([
        'baid' => $otherPlayer->baid,
        'game_version' => 'green',
        'song_no' => 21,
        'level' => 3,
        'best_score' => 900,
        'best_score_rank' => 8,
        'best_play_result' => 2,
        'best_crown' => 2,
    ]);

    SongPlayResult::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'played_at' => now(),
        'song_no' => 20,
        'level' => 4,
        'play_result' => 3,
        'score' => 900,
        'score_rank' => 8,
        'good_count' => 400,
        'ok_count' => 20,
        'miss_count' => 0,
        'combo_count' => 420,
    ]);
    SongPlayResult::query()->create([
        'baid' => $player->baid,
        'game_version' => 'blue',
        'played_at' => now(),
        'song_no' => 20,
        'level' => 4,
        'play_result' => 3,
        'score' => 999999,
        'score_rank' => 10,
    ]);

    PlayerRankSnapshot::query()->create([
        'user_id' => $user->id,
        'game_version' => 'green',
        'rank' => 3,
        'total_score' => 1200,
        'ranked_song_count' => 2,
        'played_song_count' => 1,
        'crown_counts' => ['none' => 0, 'clear' => 0, 'gold' => 1, 'dondaful' => 1],
        'snapshot_date' => now()->subDay()->toDateString(),
    ]);
    PlayerRankSnapshot::query()->create([
        'user_id' => $user->id,
        'game_version' => 'green',
        'rank' => 2,
        'total_score' => 1700,
        'ranked_song_count' => 2,
        'played_song_count' => 1,
        'crown_counts' => ['none' => 0, 'clear' => 0, 'gold' => 1, 'dondaful' => 1],
        'snapshot_date' => now()->toDateString(),
    ]);

    $this->get("/green/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Board')
            ->where('hasPlayer', true)
            ->where('profile.id', $user->id)
            ->where('profile.name', 'Firestorm7893')
            ->where('profile.mydon_name', 'DON')
            ->where('profile.game_version.value', 'green')
            ->missing('profile.email')
            ->missing('profile.baid')
            ->where('summary.rank', 2)
            ->where('summary.total_score', 1700)
            ->where('summary.crown_counts.dondaful', 1)
            ->where('summary.crown_counts.gold', 1)
            ->missing('summary.user_id')
            ->has('rankHistory', 2)
            ->has('recentPlays', 1)
            ->where('recentPlays.0.song_title', 'Green Song')
            ->missing('recentPlays.0.baid')
            ->has('bestPerformances', 2)
            ->where('bestPerformances.0.song_title', 'Green Song')
            ->where('bestPerformances.0.placement', 2)
        );
});

it('renders an empty public board for users without a linked player', function (): void {
    $user = User::factory()->create(['name' => 'No Card']);

    $this->get("/green/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Board')
            ->where('hasPlayer', false)
            ->where('profile.name', 'No Card')
            ->where('summary.rank', null)
            ->has('recentPlays', 0)
            ->has('bestPerformances', 0)
        );
});

it('does not expose all-version boards publicly', function (): void {
    $user = User::factory()->create();

    $this->get("/all/users/{$user->id}/board")->assertNotFound();
});

it('shows blue battle data on the blue version board', function (): void {
    $user = User::factory()->create(['name' => 'BlueFighter']);
    $player = Player::query()->create([
        'mydon_name' => 'BATTLE',
        'user_id' => $user->id,
    ]);

    // Create blue battle state
    $userState = PlayerBlueBattleState::query()->create([
        'baid' => $player->baid,
        'assign_stage_id' => 5,
        'last_battle_stage_id' => 4,
        'last_boss_life' => 12,
        'last_npc_id' => 3,
    ]);

    $npcState = PlayerBlueBattleNpcState::query()->create([
        'baid' => $player->baid,
        'npc_id' => 3,
        'total_exp' => 250,
        'max_dpn' => 15,
        'npc_costume_id' => 1,
        'selected_special_id_1' => 1,
        'selected_special_id_2' => 2,
        'selected_special_id_3' => 0,
        'bonds_level' => 3,
    ]);

    $tokenState = PlayerBlueBattleTokenState::query()->create([
        'baid' => $player->baid,
        'token_id' => 1,
        'token_value' => 50,
    ]);

    // Query green board - blueBattleData should be null
    $this->get("/green/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Board')
            ->where('blueBattleData', null)
        );

    // Query blue board - blueBattleData should contain the populated data
    $this->get("/blue/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Board')
            ->where('blueBattleData.assign_stage_id', 5)
            ->where('blueBattleData.last_battle_stage_id', 4)
            ->where('blueBattleData.last_boss_life', 12)
            ->where('blueBattleData.last_npc_id', 3)
            ->where('blueBattleData.npcs.0.npc_id', 3)
            ->where('blueBattleData.npcs.0.total_exp', 250)
            ->where('blueBattleData.npcs.0.max_dpn', 15)
            ->where('blueBattleData.npcs.0.selected_special_id_2', 2)
            ->where('blueBattleData.npcs.0.bonds_level', 3)
            ->where('blueBattleData.tokens.0.token_id', 1)
            ->where('blueBattleData.tokens.0.token_value', 50)
        );
});

it('shows dan dojo progress on an AC15 version board and scopes it per version', function (): void {
    $user = User::factory()->create(['name' => 'Danplayer']);
    $player = Player::query()->create(['user_id' => $user->id]);

    $progress = PlayerDanProgress::resolve((int) $player->baid, TaikoGameVersion::Green);
    $progress->recordDanPlay(1, PlayerDanProgress::GRADE_GOLD_CLEAR);
    $progress->recordDanPlay(2, PlayerDanProgress::GRADE_NORMAL_CLEAR);
    $progress->save();

    $this->get("/green/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Board')
            ->where('daniData.got_dan_max', 2)
            ->where('daniData.disp_taikojuku_dan', 3)
            ->where('daniData.dans.0.dan', 1)
            ->where('daniData.dans.0.grade', 2)
            ->where('daniData.dans.1.dan', 2)
            ->where('daniData.dans.1.grade', 1)
        );

    // No Red progress for this player, so the Red board reports null.
    $this->get("/red/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('daniData', null));
});

it('shows green ghost data on the green version board', function (): void {
    $user = User::factory()->create(['name' => 'GreenGhost']);
    $player = Player::query()->create([
        'mydon_name' => 'GHOST',
        'user_id' => $user->id,
    ]);

    // Create green ghost state
    PlayerGreenGhostState::query()->create([
        'baid' => $player->baid,
        'total_winnings' => 1500,
        'input_median' => 12,
        'input_variance' => 34,
        'rank_id' => 3,
        'win_point' => 500,
        'certified_level_id' => 2,
        'release_info_flag' => 'dummy',
    ]);

    PlayerGreenGhostToken::query()->create([
        'baid' => $player->baid,
        'token_id' => 1,
        'token_value' => 10,
    ]);

    PlayerGreenGhostWinnings::query()->create([
        'baid' => $player->baid,
        'level_id' => 2,
        'winnings' => 5,
    ]);

    // Query blue board - greenGhostData should be null
    $this->get("/blue/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Board')
            ->where('greenGhostData', null)
        );

    // Query green board - greenGhostData should contain the populated data
    $this->get("/green/users/{$user->id}/board")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Board')
            ->where('greenGhostData.total_winnings', 1500)
            ->where('greenGhostData.input_median', 12)
            ->where('greenGhostData.input_variance', 34)
            ->where('greenGhostData.rank_id', 3)
            ->where('greenGhostData.win_point', 500)
            ->where('greenGhostData.certified_level_id', 2)
            ->where('greenGhostData.tokens.0.token_id', 1)
            ->where('greenGhostData.tokens.0.token_value', 10)
            ->where('greenGhostData.winnings.0.level_id', 2)
            ->where('greenGhostData.winnings.0.winnings', 5)
        );
});

function createBoardSong(string $version, int $songNo, string $title): void
{
    Song::query()->create([
        'version' => $version,
        'song_no' => $songNo,
        'music_id' => "{$version}-{$songNo}",
        'unique_id' => $songNo,
        'title' => $title,
        'genre' => SongGenre::Jpop,
        'partsset' => SongPartsSet::Taiko,
        'wai2_partsset' => SongWai2PartsSet::Taiko,
        'flags' => [],
        'tags' => [],
    ]);
}
