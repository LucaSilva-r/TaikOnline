<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WdbChart;
use App\Services\WaddamburoRankAggregateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Ranking Waddamburo charts: only ranked charts count towards the website's standings. */
class WaddamburoChartController extends Controller
{
    /** Sortable columns: request key => SQL expression. */
    private const SORTS = [
        'title' => 'title',
        'source' => 'source',
        'course' => 'level * 10 + course',
        'level' => 'level',
        'plays' => 'plays_count',
        'ranked' => 'ranked_at IS NOT NULL',
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $source = $filters['source'] ?? null;
        $sort = $filters['sort'] ?? 'plays';
        $direction = $filters['direction'] ?? 'desc';

        // A song's id is its lowest chart id among the charts sharing its title, subtitle and source.
        $charts = WdbChart::query()
            ->select(['id', 'sha256', 'title', 'subtitle', 'source', 'course', 'level', 'ranked_at'])
            ->selectRaw('notes IS NOT NULL AS has_notes')
            ->selectRaw('CASE WHEN title IS NULL THEN NULL ELSE MIN(id) OVER (PARTITION BY title, subtitle, source) END AS song_id')
            ->withCount('plays');

        return Inertia::render('admin/WaddamburoCharts', [
            'charts' => WdbChart::query()
                ->fromSub($charts, 'wdb_charts')
                ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('title', 'ilike', "%{$search}%")->orWhere('sha256', 'like', "{$search}%")))
                ->when($source !== null, fn ($query) => $query->where('source', $source))
                ->orderByRaw(self::SORTS[$sort].' '.$direction.' NULLS LAST')
                ->orderBy('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (WdbChart $chart): array => [
                    ...$chart->only(['id', 'sha256', 'title', 'subtitle', 'source', 'course', 'level']),
                    'has_notes' => (bool) $chart->has_notes,
                    'song_id' => $chart->song_id === null ? null : (int) $chart->song_id,
                    'ranked' => $chart->ranked_at !== null,
                    'plays_count' => (int) $chart->plays_count,
                ]),
            'sources' => WdbChart::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source'),
            'filters' => ['q' => $search, 'source' => $source, 'sort' => $sort, 'direction' => $direction],
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
