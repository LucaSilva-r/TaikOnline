<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WdbChart;
use App\Services\WaddamburoRankAggregateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Ranking Waddamburo charts: only ranked charts count towards the website's standings. */
class WaddamburoChartController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        return Inertia::render('admin/WaddamburoCharts', [
            'charts' => WdbChart::query()
                ->withCount('plays')
                ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('title', 'ilike', "%{$search}%")->orWhere('sha256', 'like', "{$search}%")))
                ->orderByRaw('ranked_at IS NULL')
                ->orderByDesc('plays_count')
                ->paginate(50, ['id', 'sha256', 'title', 'subtitle', 'source', 'course', 'level', 'ranked_at'])
                ->withQueryString()
                ->through(fn (WdbChart $chart): array => [
                    ...$chart->only(['id', 'sha256', 'title', 'subtitle', 'source', 'course', 'level']),
                    'has_notes' => WdbChart::query()->whereKey($chart->id)->whereNotNull('notes')->exists(),
                    'ranked' => $chart->ranked_at !== null,
                    'plays_count' => (int) $chart->plays_count,
                ]),
            'filters' => ['q' => $search],
        ]);
    }

    public function update(Request $request, WdbChart $chart, WaddamburoRankAggregateService $aggregates): RedirectResponse
    {
        $ranked = $request->validate(['ranked' => ['required', 'boolean']])['ranked'];
        $chart->update(['ranked_at' => $ranked ? ($chart->ranked_at ?? now()) : null]);
        $aggregates->recomputeChart($chart->id);

        return back();
    }
}
