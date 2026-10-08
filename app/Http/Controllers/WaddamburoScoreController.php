<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateWaddamburo;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Waddamburo's score lists and replays, as osu! has them: a chart's leaderboard (each player's best
 * play), the player's own plays on it, and any play's replay to watch.
 */
class WaddamburoScoreController extends Controller
{
    /** Leaderboard rows shown at most. */
    public const LIMIT = 50;

    /** Every column but the replay (the lists never send it). */
    private const COLUMNS = ['id', 'baid', 'wdb_chart_id', 'mode', 'course', 'score', 'great', 'good', 'miss',
        'max_combo', 'rolls', 'gauge', 'cleared', 'scoring_version', 'played_at', 'audio_offset_ms', 'input_offset_ms',
        'options', 'seed'];

    /**
     * A chart's leaderboard: each player's best play the server scored (the earlier one on a tie), best first, and the
     * asking player's own best with its place when it is further down. Unknown charts have none.
     * ?shinuchi=1: the 真打 plays' board (they score on their own scale), else the normal one.
     */
    public function scores(Request $request, string $sha256): JsonResponse
    {
        $chart = $this->chart($sha256);
        if ($chart === null) {
            return response()->json(['scores' => [], 'mine' => null]);
        }
        $bests = WdbPlay::query()
            ->fromSub(WdbPlay::query()->select(self::COLUMNS)
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY baid ORDER BY score DESC, played_at ASC) AS player_best')
                ->where('wdb_chart_id', $chart->id)
                ->whereNotNull('rescored_at')
                ->whereRaw('(options & ?) '.($request->boolean('shinuchi') ? '<>' : '=').' 0', [WdbPlay::SHINUCHI]), 'wdb_plays')
            ->where('player_best', 1)
            ->orderByDesc('score')->orderBy('played_at');
        $top = (clone $bests)->limit(self::LIMIT)->with('player.user')->get()->values();
        $rows = $top->map(fn (WdbPlay $play, int $index): array => $this->row($play, $index + 1))->all();

        $mine = null;
        if (($baid = $this->baid($request)) !== null) {
            $index = $top->search(fn (WdbPlay $play): bool => (int) $play->baid === $baid);
            if ($index !== false) {
                $mine = $rows[$index];
            } elseif (($own = (clone $bests)->where('baid', $baid)->with('player.user')->first()) !== null) {
                $better = (clone $bests)->where(fn (Builder $query) => $query->where('score', '>', $own->score)
                    ->orWhere(fn (Builder $tie) => $tie->where('score', $own->score)->where('played_at', '<', $own->played_at)))->count();
                $mine = $this->row($own, $better + 1);
            }
        }

        return response()->json(['scores' => $rows, 'mine' => $mine]);
    }

    /** The asking player's plays on a chart, best first (a cabinet names the player with ?baid=). */
    public function mine(Request $request, string $sha256): JsonResponse
    {
        $chart = $this->chart($sha256);
        $baid = $this->baid($request);
        abort_if($baid === null, 404);
        $plays = $chart === null ? collect() : WdbPlay::query()->select(self::COLUMNS)
            ->where('wdb_chart_id', $chart->id)->where('baid', $baid)
            ->orderByDesc('score')->orderByDesc('played_at')->limit(100)
            ->with('player.user')->get();

        return response()->json(['scores' => $plays->map(fn (WdbPlay $play): array => $this->row($play, null))->values()->all()]);
    }

    /** A play's replay (base64) and what playing it back needs. */
    public function replay(string $id): JsonResponse
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $id) === 1, 404);
        $play = WdbPlay::query()->with(['chart:id,sha256', 'player.user'])->findOrFail($id);

        return response()->json([
            ...$this->row($play, null),
            'chart_sha256' => $play->chart->sha256,
            // Who played it, as their game shows them (name boards, Don's look): a replay shows its player.
            // ponytail: their look now, not when they played (looks are not kept per play).
            'player' => $play->player === null ? null : WaddamburoController::profile($play->player),
            'replay' => base64_encode((string) $play->replay),
        ]);
    }

    private function chart(string $sha256): ?WdbChart
    {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $sha256) === 1, 404);

        return WdbChart::query()->where('sha256', $sha256)->first();
    }

    // The player asking: a home token's own, or the one a cabinet names (?baid=).
    private function baid(Request $request): ?int
    {
        if ($request->attributes->get(AuthenticateWaddamburo::CABINET) === true) {
            $baid = $request->query('baid');

            return is_numeric($baid) ? (int) $baid : null;
        }
        $baid = $request->user()?->player?->baid;

        return $baid === null ? null : (int) $baid;
    }

    /** @return array<string, mixed> one play as the lists show it (crown: 0 none, 1 clear, 2 full combo, 3 all Great) */
    private function row(WdbPlay $play, ?int $rank): array
    {
        return [
            'play_id' => $play->id,
            'rank' => $rank,
            'baid' => (int) $play->baid,
            // The account's public name (as on the name boards), else the Don-chan's.
            'name' => (string) ($play->player?->user?->name ?? $play->player?->mydon_name ?? ''),
            'score' => (int) $play->score,
            'great' => (int) $play->great,
            'good' => (int) $play->good,
            'miss' => (int) $play->miss,
            'max_combo' => (int) $play->max_combo,
            'rolls' => (int) $play->rolls,
            'gauge' => (int) $play->gauge,
            'cleared' => (bool) $play->cleared,
            'crown' => match (true) {
                $play->cleared && $play->miss === 0 && $play->good === 0 => 3,
                $play->cleared && $play->miss === 0 => 2,
                (bool) $play->cleared => 1,
                default => 0,
            },
            'course' => (int) $play->course,
            'played_at' => $play->played_at?->toIso8601String(),
            'audio_offset_ms' => $play->audio_offset_ms,
            'input_offset_ms' => $play->input_offset_ms,
            'options' => (int) $play->options,
            'seed' => $play->seed === null ? null : (int) $play->seed,
        ];
    }
}
