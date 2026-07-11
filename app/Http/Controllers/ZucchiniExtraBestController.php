<?php

namespace App\Http\Controllers;

use App\Models\ExtraChartBest;
use App\Models\GameCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZucchiniExtraBestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access_code' => ['required', 'string', 'size:20'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);

        $card = GameCard::query()->whereKey($validated['access_code'])->first();
        if (! $card instanceof GameCard) {
            return response()->json(['data' => [], 'next_cursor' => null]);
        }

        $cursor = (int) ($validated['cursor'] ?? 0);
        $rows = ExtraChartBest::query()
            ->join('extra_charts', 'extra_charts.id', '=', 'extra_chart_bests.extra_chart_id')
            ->where('extra_chart_bests.baid', $card->baid)
            ->where('extra_chart_bests.is_shin', false)
            ->where('extra_chart_bests.id', '>', $cursor)
            ->orderBy('extra_chart_bests.id')
            ->limit(513)
            ->get([
                'extra_chart_bests.id', 'extra_chart_bests.is_shin',
                'extra_chart_bests.best_score', 'extra_chart_bests.best_crown',
                'extra_charts.sha256',
            ]);

        $hasMore = $rows->count() > 512;
        $page = $rows->take(512);

        return response()->json([
            'data' => $page->map(fn (ExtraChartBest $best): array => [
                'sha256' => $best->sha256,
                'is_shin' => (bool) $best->is_shin,
                'best_score' => (int) $best->best_score,
                'best_crown' => (int) $best->best_crown,
            ])->values(),
            'next_cursor' => $hasMore ? (int) $page->last()->id : null,
        ]);
    }
}
