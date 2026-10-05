<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WdbChart;
use App\Models\WdbSong;
use App\Services\WaddamburoRankAggregateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ranking Waddamburo charts, listed by song (expand a song for its charts): only ranked charts count
 * towards the website's standings. A whole song can be ranked at once.
 */
class WaddamburoChartController extends Controller
{
    /** Sortable columns: request key => SQL expression over the songs. */
    private const SORTS = [
        'title' => 'COALESCE(title, title_en)',
        'plays' => 'plays_count',
        'ranked' => 'ranked_count',
        'added' => 'id',
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
            'ranked' => ['nullable', 'in:all,none,some'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $source = $filters['source'] ?? null;
        $ranked = $filters['ranked'] ?? null;
        $sort = $filters['sort'] ?? 'plays';
        $direction = $filters['direction'] ?? 'desc';

        $songCharts = fn (): QueryBuilder => DB::table('wdb_chart_song')->select('wdb_chart_id')
            ->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id');
        $songs = WdbSong::query()
            ->select('*')
            ->selectSub(DB::table('wdb_charts')->selectRaw('COUNT(*)')->whereIn('id', $songCharts()), 'chart_count')
            ->selectSub(DB::table('wdb_charts')->selectRaw('COUNT(*)')->whereIn('id', $songCharts())->whereNotNull('ranked_at'), 'ranked_count')
            ->selectSub(DB::table('wdb_plays')->selectRaw('COUNT(*)')->whereIn('wdb_chart_id', $songCharts()), 'plays_count');

        $page = WdbSong::query()
            ->fromSub($songs, 'wdb_songs')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereLike('title', "%{$search}%")->orWhereLike('title_en', "%{$search}%")
                ->orWhere('song_key', $search)
                ->orWhereExists(fn (QueryBuilder $chart) => $chart->from('wdb_chart_song')
                    ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_chart_song.wdb_chart_id')
                    ->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id')->where('sha256', 'like', strtolower($search).'%'))))
            ->when($source !== null, fn (Builder $query) => $query->whereExists(fn (QueryBuilder $link) => $link
                ->from('wdb_chart_song')->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id')->where('source', $source)))
            ->when($ranked === 'all', fn (Builder $query) => $query->whereColumn('ranked_count', 'chart_count'))
            ->when($ranked === 'none', fn (Builder $query) => $query->where('ranked_count', 0))
            ->when($ranked === 'some', fn (Builder $query) => $query->where('ranked_count', '>', 0)->whereColumn('ranked_count', '<', 'chart_count'))
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' NULLS LAST')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        // The page's charts, once each with every source shipping it in its song.
        $links = DB::table('wdb_chart_song')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_chart_song.wdb_chart_id')
            ->whereIn('wdb_song_id', $page->pluck('id'))
            ->select('wdb_chart_song.wdb_song_id', 'wdb_chart_song.source', 'wdb_charts.id', 'wdb_charts.sha256',
                'wdb_charts.course', 'wdb_charts.level', 'wdb_charts.difficulty', 'wdb_charts.ranked_at')
            ->selectRaw('wdb_charts.notes IS NOT NULL AS has_notes')
            ->selectSub(DB::table('wdb_plays')->selectRaw('COUNT(*)')->whereColumn('wdb_plays.wdb_chart_id', 'wdb_charts.id'), 'plays_count')
            ->get()
            ->groupBy('wdb_song_id');

        return Inertia::render('admin/WaddamburoCharts', [
            'songs' => $page->through(fn (WdbSong $song): array => [
                'id' => $song->id,
                'title' => $song->title ?? $song->title_en,
                'title_en' => $song->title_en,
                'subtitle' => $song->subtitle,
                'family' => $song->family,
                'song_key' => $song->song_key,
                'chart_count' => (int) $song->chart_count,
                'ranked_count' => (int) $song->ranked_count,
                'plays_count' => (int) $song->plays_count,
                'sources' => $links->get($song->id, collect())->pluck('source')->unique()->values()->all(),
                'charts' => $links->get($song->id, collect())->groupBy('id')->map(fn ($rows): array => [
                    'id' => (int) $rows->first()->id,
                    'sha256' => $rows->first()->sha256,
                    'course' => $rows->first()->course === null ? null : (int) $rows->first()->course,
                    'level' => $rows->first()->level === null ? null : (int) $rows->first()->level,
                    'difficulty' => $rows->first()->difficulty,
                    'sources' => $rows->pluck('source')->unique()->values()->all(),
                    'has_notes' => (bool) $rows->first()->has_notes,
                    'ranked' => $rows->first()->ranked_at !== null,
                    'plays_count' => (int) $rows->first()->plays_count,
                ])->sortBy(['course', 'id'])->values()->all(),
            ]),
            'sources' => DB::table('wdb_chart_song')->distinct()->orderBy('source')->pluck('source'),
            'unlinkedCharts' => WdbChart::query()->whereDoesntHave('songs')->count(),
            'filters' => ['q' => $search, 'source' => $source, 'ranked' => $ranked, 'sort' => $sort, 'direction' => $direction],
        ]);
    }

    public function update(Request $request, WdbChart $chart, WaddamburoRankAggregateService $aggregates): RedirectResponse
    {
        $ranked = $request->validate(['ranked' => ['required', 'boolean']])['ranked'];
        $chart->update(['ranked_at' => $ranked ? ($chart->ranked_at ?? now()) : null]);
        $aggregates->recomputeChart($chart->id);

        return back();
    }

    /** Ranks or unranks every chart of a song. */
    public function updateSong(Request $request, WdbSong $song, WaddamburoRankAggregateService $aggregates): RedirectResponse
    {
        $ranked = $request->validate(['ranked' => ['required', 'boolean']])['ranked'];
        $charts = $song->charts()->pluck('wdb_charts.id')->unique();
        WdbChart::query()->whereIn('id', $charts)
            ->when($ranked, fn (Builder $query) => $query->whereNull('ranked_at')->update(['ranked_at' => now()]),
                fn (Builder $query) => $query->update(['ranked_at' => null]));
        $charts->each(fn (int $id) => $aggregates->recomputeChart($id));

        return back();
    }
}
