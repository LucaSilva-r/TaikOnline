<?php

namespace App\Console\Commands;

use App\Http\Controllers\WaddamburoController;
use App\Models\Player;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoScorer;
use App\Services\WaddamburoSongs;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Imports the charts of the client's --export-charts (run on unmodified Green and Nijiiro installs) as
 * gzipped JSON lines, each the
 * import PUT api/wdb/charts/{sha256} takes plus its sha256. Known charts keep their notes and metadata,
 * fill in metadata they lack (English titles). Green charts are marked stock and ranked. Nijiiro ones only with
 * --nijiiro-dumps, which says the files are exports of unmodified Nijiiro dumps (JPN08 and JPN39 so far): their
 * charts become official and ranked, and every other Nijiiro chart custom and unranked, its plays kept. Repacks ship
 * other games' and fan-made charts, so what they contain proves nothing. A chart Green also ships stays official, but
 * only as Green's unless a dump has it. --unrank-custom then unranks every chart not marked stock, admin-ranked ones
 * too (their scores stay, out of the standings), after a confirmation that --force skips. A line's legacy_sha256 names
 * the chart older clients played in its place (a 譜面分岐 chart read as its normal route): that chart is marked legacy
 * and unranked, its plays kept apart.
 */
#[Signature('app:import-waddamburo-charts
    {files* : The files written by Waddamburo --export-charts=FILE on unmodified installs}
    {--nijiiro-dumps : The files are exports of unmodified Nijiiro dumps: only their Nijiiro charts are official}
    {--unrank-custom : Unrank every chart that is not stock, including ones an admin ranked}
    {--force : Unrank without asking}')]
#[Description('Import, mark stock and rank the official Waddamburo charts by hash')]
class ImportWaddamburoChartsCommand extends Command
{
    public function handle(WaddamburoRankAggregateService $aggregates, WaddamburoScorer $scorer): int
    {
        $files = [];
        foreach ((array) $this->argument('files') as $path) {
            $file = is_readable($path) ? fopen('compress.zlib://'.$path, 'r') : false;
            if ($file === false) {
                $this->error("Cannot read {$path}.");

                return self::FAILURE;
            }
            $files[] = $file;
        }
        $dumps = (bool) $this->option('nijiiro-dumps');
        $dumped = [];
        $official = 0;
        $now = now();
        $imported = 0;
        $skipped = 0;
        $rows = [];
        $links = [];
        $exportedSongs = [];
        $legacy = [];
        $flush = function () use (&$rows, &$links, &$exportedSongs): void {
            $keep = fn (string $column): Expression => DB::raw(
                "CASE WHEN wdb_charts.notes IS NULL THEN excluded.{$column} ELSE wdb_charts.{$column} END");
            DB::table('wdb_charts')->upsert(array_values($rows), ['sha256'], [
                'notes' => $keep('notes'), 'title' => $keep('title'), 'subtitle' => $keep('subtitle'),
                'source' => $keep('source'), 'course' => $keep('course'), 'level' => $keep('level'),
                // Official by any line (a Green chart a community song also ships stays official).
                'is_stock' => DB::raw('wdb_charts.is_stock OR excluded.is_stock'),
                // A legacy chart stays unranked (an older export can still list it).
                'ranked_at' => DB::raw('CASE WHEN wdb_charts.legacy_at IS NULL THEN COALESCE(wdb_charts.ranked_at, excluded.ranked_at) END'),
                ...collect(WaddamburoController::LATER_METADATA)->mapWithKeys(fn (string $column): array => [
                    $column => DB::raw("COALESCE(wdb_charts.{$column}, excluded.{$column})")])->all(),
                'updated_at' => DB::raw('excluded.updated_at'),
            ]);
            // Every line joins its song, also a chart another line (stock and Nijiiro share charts) brought.
            $ids = DB::table('wdb_charts')->whereIn('sha256', array_keys($rows))->pluck('id', 'sha256');
            WaddamburoSongs::attach(array_map(fn (array $link): array => ['wdb_chart_id' => $ids[$link['sha256']], ...$link], $links));
            foreach ($links as $link) {
                $exportedSongs[(string) $link['song_key']] = true;
            }
            $rows = [];
            $links = [];
        };

        foreach ($files as $file) {
            $this->importLines($file, $rows, $links, $legacy, $dumps, $dumped, $imported, $official, $skipped, $now, $flush);
            fclose($file);
        }
        if ($rows !== []) {
            $flush();
        }
        $madeCustom = 0;
        $greenOnly = 0;
        if ($dumps) {
            $links = fn (string $source) => DB::table('wdb_chart_song')->select('wdb_chart_id')->where('source', $source);
            // The Nijiiro charts no dump has: custom, or Green's alone when Green ships them.
            WdbChart::query()->whereIn('id', $links('Nijiiro'))->select(['id', 'sha256'])->chunkById(1000, function ($charts) use ($dumped, &$madeCustom, &$greenOnly, $links): void {
                $others = $charts->reject(fn (WdbChart $chart): bool => isset($dumped[$chart->sha256]))->pluck('id');
                $madeCustom += WdbChart::query()->whereIn('id', $others)->whereNotIn('id', $links('Stock'))
                    ->update(['is_stock' => false, 'ranked_at' => null]);
                $greenOnly += DB::table('wdb_chart_song')->where('source', 'Nijiiro')->whereIn('wdb_chart_id', $others)
                    ->whereIn('wdb_chart_id', $links('Stock'))->delete();
            });
        }
        $legacyCharts = 0;
        foreach (array_chunk(array_keys($legacy), 1000) as $chunk) {
            $legacyCharts += WdbChart::query()->whereIn('sha256', $chunk)->whereNull('legacy_at')
                ->update(['legacy_at' => $now, 'ranked_at' => null]);
        }
        $unranked = 0;
        if ($this->option('unrank-custom')) {
            $custom = fn () => WdbChart::query()->where('is_stock', false)->whereNotNull('ranked_at');
            $count = $custom()->count();
            if ($count > 0 && ($this->option('force') || $this->confirm(
                "Unrank {$count} ranked charts that are not official, including any an admin ranked by hand?"))) {
                $unranked = $custom()->update(['ranked_at' => null]);
            }
        }

        // Plays waiting for these charts' notes score now.
        WdbPlay::query()->whereNull('rescored_at')->chunkById(500, fn ($plays) => $scorer->rescore($plays));
        Player::query()
            ->whereIn('baid', DB::table('wdb_plays')->select('baid')->distinct())
            ->each(fn (Player $player) => $aggregates->recompute($player));

        $this->info("Imported {$imported} charts, {$official} official and ranked ({$skipped} invalid lines skipped).");
        if ($legacyCharts > 0) {
            $this->info("Marked {$legacyCharts} charts legacy (older clients' reading of a branched chart).");
        }
        if ($dumps) {
            $this->info('Nijiiro dumps: '.count($dumped)." charts official, {$madeCustom} others made custom, {$greenOnly} marked as Green's only.");
        }
        if ($this->option('unrank-custom')) {
            $this->info("Unranked {$unranked} charts that are not stock.");
        }

        return self::SUCCESS;
    }

    /**
     * Queues one export's lines for $flush (every 500 charts).
     *
     * @param  resource  $file
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $links
     * @param  array<string, true>  $legacy  the legacy_sha256 values seen
     * @param  array<string, int>  $listed  the official Nijiiro song ids (keys)
     */
    private function importLines($file, array &$rows, array &$links, array &$legacy, bool $dumps, array &$dumped, int &$imported, int &$official, int &$skipped, CarbonInterface $now, Closure $flush): void
    {
        while (($line = fgets($file)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $chart = json_decode($line, true);
            $sha256 = is_array($chart) ? (string) ($chart['sha256'] ?? '') : '';
            $compressed = base64_decode((string) ($chart['notes'] ?? ''), true);
            $notes = $compressed === false ? false : @gzdecode($compressed, WaddamburoController::MAX_CHART_BYTES);
            if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1 || $notes === false || ! hash_equals($sha256, hash('sha256', $notes))) {
                $skipped++;

                continue;
            }

            $old = (string) ($chart['legacy_sha256'] ?? '');
            if (preg_match('/\A[0-9a-f]{64}\z/', $old) === 1 && $old !== $sha256) {
                $legacy[$old] = true;
            }

            $isOfficial = ($chart['source'] ?? '') !== 'Nijiiro' || $dumps;
            if ($dumps && ($chart['source'] ?? '') === 'Nijiiro') {
                $dumped[$sha256] = true;
            }
            $isOfficial = $isOfficial || ($rows[$sha256]['is_stock'] ?? false);
            $official += $isOfficial ? 1 : 0;
            // Keyed by hash: Postgres refuses an upsert touching the same row twice.
            $rows[$sha256] = [
                'sha256' => $sha256,
                'notes' => '\x'.bin2hex($compressed),
                'title' => isset($chart['title']) ? mb_substr((string) $chart['title'], 0, 255) : null,
                'subtitle' => isset($chart['subtitle']) ? mb_substr((string) $chart['subtitle'], 0, 255) : null,
                'source' => isset($chart['source']) ? mb_substr((string) $chart['source'], 0, 255) : null,
                'is_stock' => $isOfficial,
                'course' => isset($chart['course']) ? (int) $chart['course'] : null,
                'level' => isset($chart['level']) ? (int) $chart['level'] : null,
                'title_en' => isset($chart['title_en']) ? mb_substr((string) $chart['title_en'], 0, 255) : null,
                'subtitle_en' => isset($chart['subtitle_en']) ? mb_substr((string) $chart['subtitle_en'], 0, 255) : null,
                'difficulty' => isset($chart['difficulty']) ? mb_substr((string) $chart['difficulty'], 0, 255) : null,
                'osu_beatmap_id' => isset($chart['osu_beatmap_id']) && (int) $chart['osu_beatmap_id'] > 0 ? (int) $chart['osu_beatmap_id'] : null,
                'osu_beatmapset_id' => isset($chart['osu_beatmapset_id']) && (int) $chart['osu_beatmapset_id'] > 0 ? (int) $chart['osu_beatmapset_id'] : null,
                'ranked_at' => $isOfficial ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $links[] = ['sha256' => $sha256, 'song_key' => $chart['song_key'] ?? null,
                ...array_intersect_key($rows[$sha256], array_flip(['source', 'title', 'subtitle', 'title_en', 'subtitle_en', 'osu_beatmapset_id']))];
            $imported++;
            if (count($rows) >= 500) {
                $flush();
            }
        }
    }
}
