<?php

namespace App\Http\Controllers;

use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Models\User;
use App\Services\PlayerRankAggregateService;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function show(Request $request, User $user, PlayerRankAggregateService $rankAggregates): Response
    {
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion || (bool) $request->attributes->get('taikoVersionIsAll', false)) {
            abort(404);
        }

        $user->load('player');

        $player = $user->player;
        if (! $player instanceof Player) {
            return Inertia::render('Board', [
                'profile' => $this->profilePayload($user, null, $version),
                'hasPlayer' => false,
                'summary' => $this->emptySummary(),
                'rankHistory' => [],
                'recentPlays' => [],
                'bestPerformances' => [],
                'blueBattleData' => null,
                'greenGhostData' => null,
                'tokkunData' => null,
            ]);
        }

        $summary = $rankAggregates->forVersion($version)->get($user->id, $this->emptySummary());
        unset($summary['user_id']);

        return Inertia::render('Board', [
            'profile' => $this->profilePayload($user, $player, $version),
            'hasPlayer' => true,
            'summary' => $summary,
            'rankHistory' => $this->rankHistory($user, $version),
            'recentPlays' => $this->recentPlays($player, $version),
            'bestPerformances' => $this->bestPerformances($player, $version),
            'blueBattleData' => $this->blueBattleData($player, $version),
            'greenGhostData' => $this->greenGhostData($player, $version),
            'tokkunData' => $this->tokkunData($player, $version),
        ]);
    }

    private function profilePayload(User $user, ?Player $player, TaikoGameVersion $version): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar ?? null,
            'mydon_name' => $player?->mydon_name ?: null,
            'game_version' => [
                'value' => $version->value,
                'label' => $version->label(),
            ],
            'last_played_at' => $player?->last_played_at?->toDateTimeString(),
            'total_credit_count' => (int) ($player?->total_credit_count ?? 0),
            'don_medals' => [
                'earned' => (int) ($player?->total_get_donmedal ?? 0),
                'spent' => (int) ($player?->total_use_donmedal ?? 0),
            ],
            'katsu_medals' => [
                'earned' => (int) ($player?->total_get_katsumedal ?? 0),
                'spent' => (int) ($player?->total_use_katsumedal ?? 0),
            ],
        ];
    }

    private function emptySummary(): array
    {
        return [
            'rank' => null,
            'total_score' => 0,
            'ranked_song_count' => 0,
            'played_song_count' => 0,
            'precision' => 0.0,
            'crown_counts' => [
                'none' => 0,
                'clear' => 0,
                'gold' => 0,
                'dondaful' => 0,
            ],
        ];
    }

    private function rankHistory(User $user, TaikoGameVersion $version): array
    {
        return PlayerRankSnapshot::query()
            ->whereBelongsTo($user)
            ->where('game_version', $version->value)
            ->latest('snapshot_date')
            ->limit(90)
            ->get()
            ->sortBy('snapshot_date')
            ->map(fn (PlayerRankSnapshot $snapshot): array => [
                'date' => $snapshot->snapshot_date->toDateString(),
                'rank' => (int) $snapshot->rank,
                'total_score' => (int) $snapshot->total_score,
            ])
            ->values()
            ->all();
    }

    private function recentPlays(Player $player, TaikoGameVersion $version): array
    {
        $resultsTable = (new SongPlayResult)->getTable();
        $songsTable = (new Song)->getTable();

        return SongPlayResult::query()
            ->where('baid', $player->baid)
            ->where("{$resultsTable}.game_version", $version->value)
            ->leftJoin($songsTable, function (JoinClause $join) use ($resultsTable, $songsTable): void {
                $join->on("{$songsTable}.version", '=', "{$resultsTable}.game_version")
                    ->on("{$songsTable}.song_no", '=', "{$resultsTable}.song_no");
            })
            ->select([
                "{$resultsTable}.song_no",
                "{$resultsTable}.level",
                "{$resultsTable}.played_at",
                "{$resultsTable}.play_result",
                "{$resultsTable}.score",
                "{$resultsTable}.score_rank",
                "{$resultsTable}.good_count",
                "{$resultsTable}.ok_count",
                "{$resultsTable}.miss_count",
                "{$resultsTable}.combo_count",
                "{$songsTable}.id as song_id",
                "{$songsTable}.title as song_title",
            ])
            ->latest("{$resultsTable}.played_at")
            ->limit(10)
            ->get()
            ->map(fn (SongPlayResult $result): array => [
                'song_title' => $result->song_title ?: "#{$result->song_no}",
                'song_id' => $result->song_id ? (int) $result->song_id : null,
                'song_no' => (int) $result->song_no,
                'level' => (int) $result->level,
                'played_at' => $result->played_at?->toDateTimeString(),
                'play_result' => (int) $result->play_result,
                'score' => (int) $result->score,
                'score_rank' => (int) $result->score_rank,
                'good_count' => (int) $result->good_count,
                'ok_count' => (int) $result->ok_count,
                'miss_count' => (int) $result->miss_count,
                'combo_count' => (int) $result->combo_count,
            ])
            ->all();
    }

    private function bestPerformances(Player $player, TaikoGameVersion $version): array
    {
        $bestsTable = (new SongBest)->getTable();
        $playersTable = (new Player)->getTable();
        $songsTable = (new Song)->getTable();

        return SongBest::query()
            ->where("{$bestsTable}.baid", $player->baid)
            ->where("{$bestsTable}.game_version", $version->value)
            ->leftJoin($songsTable, function (JoinClause $join) use ($bestsTable, $songsTable): void {
                $join->on("{$songsTable}.version", '=', "{$bestsTable}.game_version")
                    ->on("{$songsTable}.song_no", '=', "{$bestsTable}.song_no");
            })
            ->select([
                "{$bestsTable}.game_version",
                "{$bestsTable}.song_no",
                "{$bestsTable}.level",
                "{$bestsTable}.best_score",
                "{$bestsTable}.best_score_rank",
                "{$bestsTable}.best_crown",
                "{$songsTable}.id as song_id",
                "{$songsTable}.title as song_title",
            ])
            ->selectSub(function ($query) use ($bestsTable, $playersTable): void {
                $query->from("{$bestsTable} as ranked_bests")
                    ->join("{$playersTable} as ranked_players", 'ranked_players.baid', '=', 'ranked_bests.baid')
                    ->whereNotNull('ranked_players.user_id')
                    ->whereColumn('ranked_bests.game_version', "{$bestsTable}.game_version")
                    ->whereColumn('ranked_bests.song_no', "{$bestsTable}.song_no")
                    ->whereColumn('ranked_bests.level', "{$bestsTable}.level")
                    ->whereColumn('ranked_bests.best_score', '>', "{$bestsTable}.best_score")
                    ->selectRaw('COUNT(*) + 1');
            }, 'placement')
            ->orderByDesc("{$bestsTable}.best_score")
            ->limit(10)
            ->get()
            ->map(fn (SongBest $best): array => [
                'song_title' => $best->song_title ?: "#{$best->song_no}",
                'song_id' => $best->song_id ? (int) $best->song_id : null,
                'song_no' => (int) $best->song_no,
                'level' => (int) $best->level,
                'score' => (int) $best->best_score,
                'score_rank' => (int) $best->best_score_rank,
                'crown' => (int) $best->best_crown,
                'placement' => (int) $best->placement,
            ])
            ->all();
    }

    private function blueBattleData(?Player $player, TaikoGameVersion $version): ?array
    {
        if ($player === null || $version !== TaikoGameVersion::Blue) {
            return null;
        }

        $userState = $player->blueBattleState;
        $npcStates = $player->blueBattleNpcStates()->orderBy('npc_id')->get();
        $tokenStates = $player->blueBattleTokenStates()->orderBy('token_id')->get();

        if ($userState === null && $npcStates->isEmpty() && $tokenStates->isEmpty()) {
            return null;
        }

        return [
            'last_battle_stage_id' => (int) ($userState?->last_battle_stage_id ?? 0),
            'last_boss_life' => (int) ($userState?->last_boss_life ?? 0),
            'last_npc_id' => (int) ($userState?->last_npc_id ?? 0),
            'assign_stage_id' => (int) ($userState?->assign_stage_id ?? 1),
            'npcs' => $npcStates->map(fn ($npc) => [
                'npc_id' => (int) $npc->npc_id,
                'total_exp' => (int) $npc->total_exp,
                'max_dpn' => (int) $npc->max_dpn,
                'npc_costume_id' => (int) $npc->npc_costume_id,
                'selected_special_id_1' => (int) $npc->selected_special_id_1,
                'selected_special_id_2' => (int) $npc->selected_special_id_2,
                'selected_special_id_3' => (int) $npc->selected_special_id_3,
                'bonds_level' => (int) $npc->bonds_level,
            ])->toArray(),
            'tokens' => $tokenStates->map(fn ($token) => [
                'token_id' => (int) $token->token_id,
                'token_value' => (int) $token->token_value,
            ])->toArray(),
        ];
    }

    private function greenGhostData(?Player $player, TaikoGameVersion $version): ?array
    {
        if ($player === null || $version !== TaikoGameVersion::Green) {
            return null;
        }

        $ghostState = $player->greenGhostState;
        $tokenStates = $player->greenGhostTokens()->orderBy('token_id')->get();
        $winningsStates = $player->greenGhostWinnings()->orderBy('level_id')->get();

        if ($ghostState === null && $tokenStates->isEmpty() && $winningsStates->isEmpty()) {
            return null;
        }

        return [
            'total_winnings' => (int) ($ghostState?->total_winnings ?? 0),
            'input_median' => (int) ($ghostState?->input_median ?? 0),
            'input_variance' => (int) ($ghostState?->input_variance ?? 0),
            'rank_id' => (int) ($ghostState?->rank_id ?? 1),
            'win_point' => (int) ($ghostState?->win_point ?? 0),
            'certified_level_id' => (int) ($ghostState?->certified_level_id ?? 0),
            'tokens' => $tokenStates->map(fn ($token) => [
                'token_id' => (int) $token->token_id,
                'token_value' => (int) $token->token_value,
            ])->toArray(),
            'winnings' => $winningsStates->map(fn ($win) => [
                'level_id' => (int) $win->level_id,
                'winnings' => (int) $win->winnings,
            ])->toArray(),
        ];
    }

    private function tokkunData(?Player $player, TaikoGameVersion $version): ?array
    {
        if ($player === null || ! in_array($version, [TaikoGameVersion::Blue, TaikoGameVersion::Yellow], true)) {
            return null;
        }

        $state = $player->tokkunStates()->where('game_version', $version->value)->first();
        $runs = $player->tokkunStageResults()
            ->where('game_version', $version->value)
            ->latest('played_at')
            ->limit(10)
            ->get();

        if ($state === null && $runs->isEmpty()) {
            return null;
        }

        // Aggregate stats
        $stats = $player->tokkunStageResults()
            ->where('game_version', $version->value)
            ->selectRaw('
                COUNT(*) as total_runs,
                SUM(tokkun_song_count) as total_songs,
                SUM(tokkun_speedchange_count) as total_speedchanges,
                SUM(tokkun_autoplay_count) as total_autoplays,
                SUM(tokkun_jump_count) as total_jumps
            ')
            ->first();

        // Resolve song titles
        $songNumbers = $runs->pluck('tokkun_song_numbers')->flatten()->unique()->all();
        $songs = Song::query()
            ->where('version', $version->value)
            ->whereIn('song_no', $songNumbers)
            ->pluck('title', 'song_no')
            ->all();

        return [
            'tokkun_tutorial_flg' => (int) ($state?->tokkun_tutorial_flg ?? 0),
            'summary' => [
                'total_runs' => (int) ($stats->total_runs ?? 0),
                'total_songs' => (int) ($stats->total_songs ?? 0),
                'total_speedchanges' => (int) ($stats->total_speedchanges ?? 0),
                'total_autoplays' => (int) ($stats->total_autoplays ?? 0),
                'total_jumps' => (int) ($stats->total_jumps ?? 0),
            ],
            'recent_runs' => $runs->map(fn ($run) => [
                'played_at' => $run->played_at->toDateTimeString(),
                'play_mode' => (int) $run->play_mode,
                'banacoin_datetime' => $run->banacoin_datetime,
                'tokkun_song_count' => (int) $run->tokkun_song_count,
                'tokkun_speedchange_count' => (int) $run->tokkun_speedchange_count,
                'tokkun_autoplay_count' => (int) $run->tokkun_autoplay_count,
                'tokkun_jump_count' => (int) $run->tokkun_jump_count,
                'songs' => collect($run->tokkun_song_numbers)->map(fn ($songNo) => [
                    'song_no' => (int) $songNo,
                    'title' => $songs[$songNo] ?? "#{$songNo}",
                ])->toArray(),
            ])->toArray(),
        ];
    }
}
