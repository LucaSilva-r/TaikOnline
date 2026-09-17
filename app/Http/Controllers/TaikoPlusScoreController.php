<?php

namespace App\Http\Controllers;

use App\GameProtocol\Support\ScoreMapper;
use App\Models\GameCard;
use App\Services\ExtraScoreService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Score submission for TaikoRecomp's Taiko+ mode.
 *
 * The recomp is not the PS3 game: it has no playresult protobuf to send for an
 * imported chart, and the Zucchini path's header side-channel only exists
 * because its scores ride along with a real cabinet packet. This takes plain
 * JSON instead and writes the same Extra tables.
 *
 * Stock songs are deliberately not accepted here. Those still go through the
 * title's own playresult queue, which owns their credit and reward handling.
 */
class TaikoPlusScoreController extends Controller
{
    public function __construct(
        private readonly ExtraScoreService $extraScores,
        private readonly ScoreMapper $scores,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'v' => ['required', 'integer', 'in:1'],
            'access_code' => ['required', 'string', 'size:20'],
            // One identifier per round, so a retried upload is the same round.
            'play_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'client' => ['required', 'string', 'max:32'],
            'game_version' => ['nullable', 'string', 'max:32'],
            'stages' => ['required', 'array', 'min:1', 'max:8'],
            'stages.*.chart_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'stages.*.source_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'stages.*.source_kind' => ['required', 'string', 'in:tja,osu,nijiiro'],
            'stages.*.title' => ['nullable', 'string', 'max:255'],
            'stages.*.source_id' => ['nullable', 'string', 'max:255'],
            'stages.*.level' => ['required', 'integer', 'min:1', 'max:5'],
            'stages.*.star_level' => ['nullable', 'integer', 'min:0', 'max:10'],
            'stages.*.score' => ['required', 'integer', 'min:0', 'max:9999999'],
            'stages.*.good' => ['required', 'integer', 'min:0', 'max:99999'],
            'stages.*.ok' => ['required', 'integer', 'min:0', 'max:99999'],
            'stages.*.miss' => ['required', 'integer', 'min:0', 'max:99999'],
            'stages.*.drumroll' => ['required', 'integer', 'min:0', 'max:99999'],
            'stages.*.combo' => ['required', 'integer', 'min:0', 'max:99999'],
            'stages.*.hits' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'stages.*.gauge' => ['required', 'integer', 'min:0', 'max:10000'],
            'stages.*.cleared' => ['required', 'boolean'],
        ]);

        $card = GameCard::query()->whereKey($validated['access_code'])->first();
        if (! $card instanceof GameCard || ! $card->player) {
            return response()->json(['result' => 'unknown_player'], 404);
        }

        // The unique key on (session_hash, stage_index) makes a repeat of the
        // same round a no-op, so a client that never saw our reply can retry.
        $existing = $card->player->extraChartPlayResults()
            ->where('session_hash', $validated['play_id'])
            ->exists();
        if ($existing) {
            return response()->json(['result' => 'duplicate', 'stages' => 0]);
        }

        $playedAt = CarbonImmutable::now();
        $version = $validated['game_version'] ?? 'green';

        DB::transaction(function () use ($validated, $card, $playedAt, $version): void {
            foreach (array_values($validated['stages']) as $index => $stage) {
                $this->extraScores->persistNormalizedStage($card->player, [
                    'sha256' => $stage['chart_key'],
                    'title' => (string) ($stage['title'] ?? ''),
                    'source_id' => (string) ($stage['source_id'] ?? ''),
                    'source_kind' => $stage['source_kind'],
                    'source_sha256' => $stage['source_sha256'],
                    'client' => $validated['client'],
                    'origin_game_version' => $version,
                    'chassis_id' => '',
                    'shop_id' => '',
                    'is_right' => false,
                    'is_two_players' => false,
                    // Imported charts have no cabinet song number.
                    'song_no' => 0,
                    'level' => $stage['level'],
                    'stage_mode' => 0,
                    'play_result' => $stage['cleared'] ? ($stage['miss'] === 0 ? 2 : 1) : 0,
                    'score' => $stage['score'],
                    'good_count' => $stage['good'],
                    'ok_count' => $stage['ok'],
                    'miss_count' => $stage['miss'],
                    'drumroll_count' => $stage['drumroll'],
                    'combo_count' => $stage['combo'],
                    'hit_count' => $stage['hits'] ?? ($stage['good'] + $stage['ok'] + $stage['miss']),
                    'music_category' => 0,
                    'selected_folder_id' => 0,
                    'is_shin' => false,
                    'cleared' => (bool) $stage['cleared'],
                    'raw_stage' => [
                        'star_level' => $stage['star_level'] ?? null,
                        'soul_gauge' => $stage['gauge'],
                    ],
                ], $index, $validated['play_id'], $playedAt, $this->scores->rankForScore((int) $stage['score']));
            }
        });

        return response()->json([
            'result' => 'ok',
            'stages' => count($validated['stages']),
        ]);
    }
}
