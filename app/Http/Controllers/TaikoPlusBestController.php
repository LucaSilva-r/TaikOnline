<?php

namespace App\Http\Controllers;

use App\Models\ExtraChartBest;
use App\Models\GameCard;
use App\Models\SongBest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One player's own bests, for the Taiko+ browser's crowns.
 *
 * Both boards come back in one call so the client makes one round trip per
 * page: imported charts keyed by chart hash, and stock songs by song number so
 * their crowns show too. This is read-only; stock scores are still written by
 * the title's own playresult path.
 *
 * The body is plain text, one record per line. The recomp has no JSON parser
 * and this saves it needing one; the same shape its pairing client already
 * reads.
 */
class TaikoPlusBestController extends Controller
{
    private const PAGE = 512;

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'access_code' => ['required', 'string', 'size:20'],
            'cursor' => ['nullable', 'integer', 'min:0'],
            'game_version' => ['nullable', 'string', 'max:32'],
        ]);

        $card = GameCard::query()->whereKey($validated['access_code'])->first();
        if (! $card instanceof GameCard) {
            return $this->text("next_cursor=\n");
        }

        $cursor = (int) ($validated['cursor'] ?? 0);
        $version = $validated['game_version'] ?? 'green';
        $lines = [];

        $extra = ExtraChartBest::query()
            ->join('extra_charts', 'extra_charts.id', '=', 'extra_chart_bests.extra_chart_id')
            ->where('extra_chart_bests.baid', $card->baid)
            ->where('extra_chart_bests.is_shin', false)
            ->where('extra_chart_bests.id', '>', $cursor)
            ->orderBy('extra_chart_bests.id')
            ->limit(self::PAGE + 1)
            ->get([
                'extra_chart_bests.id', 'extra_chart_bests.best_score',
                'extra_chart_bests.best_crown', 'extra_charts.sha256',
            ]);

        $hasMore = $extra->count() > self::PAGE;
        $page = $extra->take(self::PAGE);
        foreach ($page as $best) {
            $lines[] = sprintf('e,%s,%d,%d', $best->sha256, (int) $best->best_score, (int) $best->best_crown);
        }

        // Stock bests are small and fixed in number, so they ride along with
        // the first page instead of having a cursor of their own.
        if ($cursor === 0) {
            $stock = SongBest::query()
                ->where('baid', $card->baid)
                ->where('game_version', $version)
                ->where('is_shin', false)
                ->orderBy('song_no')
                ->get(['song_no', 'level', 'best_score', 'best_crown']);
            foreach ($stock as $best) {
                $lines[] = sprintf(
                    's,%d,%d,%d,%d',
                    (int) $best->song_no, (int) $best->level,
                    (int) $best->best_score, (int) $best->best_crown,
                );
            }
        }

        $lines[] = 'next_cursor='.($hasMore ? (int) $page->last()->id : '');

        return $this->text(implode("\n", $lines)."\n");
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
