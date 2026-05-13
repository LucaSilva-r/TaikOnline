<?php

namespace App\Http\Controllers\Green;

use App\GameProtocol\Green\Support\GameDataCatalog;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\SongPlayResult;
use Inertia\Inertia;
use Inertia\Response;

class OperatorController extends Controller
{
    public function players(): Response
    {
        return Inertia::render('admin/Players', [
            'players' => Player::query()
                ->with('card')
                ->withCount(['playResults', 'songBests'])
                ->latest('updated_at')
                ->paginate(25)
                ->through(fn (Player $player): array => [
                    'baid' => $player->baid,
                    'mydon_name' => $player->mydon_name,
                    'access_code' => $player->card?->access_code,
                    'last_played_at' => optional($player->last_played_at)->toDateTimeString(),
                    'play_results_count' => $player->play_results_count,
                    'song_bests_count' => $player->song_bests_count,
                ]),
        ]);
    }

    public function player(Player $player): Response
    {
        $player->load(['card', 'songBests' => fn ($query) => $query->orderByDesc('best_score')->limit(20)]);

        return Inertia::render('admin/PlayerDetail', [
            'player' => [
                'baid' => $player->baid,
                'mydon_name' => $player->mydon_name,
                'access_code' => $player->card?->access_code,
                'last_played_at' => optional($player->last_played_at)->toDateTimeString(),
                'total_credit_count' => $player->total_credit_count,
                'recent_song_numbers' => $player->recent_song_numbers ?? [],
            ],
            'recentResults' => $player->playResults()
                ->latest('played_at')
                ->limit(25)
                ->get()
                ->map(fn (SongPlayResult $result): array => [
                    'game_version' => $result->game_version,
                    'song_no' => $result->song_no,
                    'level' => $result->level,
                    'score' => $result->score,
                    'score_rank' => $result->score_rank,
                    'played_at' => optional($result->played_at)->toDateTimeString(),
                ]),
            'bests' => $player->songBests->map(fn ($best): array => [
                'game_version' => $best->game_version,
                'song_no' => $best->song_no,
                'level' => $best->level,
                'best_score' => $best->best_score,
                'best_score_rank' => $best->best_score_rank,
            ]),
        ]);
    }

    public function recentPlays(): Response
    {
        return Inertia::render('admin/RecentPlays', [
            'results' => SongPlayResult::query()
                ->with('player')
                ->latest('played_at')
                ->paginate(50)
                ->through(fn (SongPlayResult $result): array => [
                    'baid' => $result->baid,
                    'mydon_name' => $result->player?->mydon_name,
                    'game_version' => $result->game_version,
                    'song_no' => $result->song_no,
                    'level' => $result->level,
                    'score' => $result->score,
                    'score_rank' => $result->score_rank,
                    'played_at' => optional($result->played_at)->toDateTimeString(),
                ]),
        ]);
    }

    public function status(GameDataCatalog $catalog): Response
    {
        return Inertia::render('admin/Status', [
            'gameData' => $catalog->status(),
            'protobuf' => [
                'taiko' => file_exists(base_path('protobuf/green/taiko.proto')),
                'vsinterface' => file_exists(base_path('protobuf/green/vsinterface.proto')),
                'taiko_blue' => file_exists(base_path('protobuf/blue/taiko.proto')),
                'vsinterface_blue' => file_exists(base_path('protobuf/blue/vsinterface.proto')),
                'generated' => file_exists(app_path('GameProtocol/Green/Proto/Taiko/BAIDRequest.php')),
            ],
        ]);
    }
}
