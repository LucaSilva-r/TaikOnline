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
 * Imports the official charts (from the client's --export-charts, run on unmodified Green and Nijiiro
 * installs only: whatever an export holds is marked stock and ranked) as gzipped JSON lines, each the
 * import PUT api/wdb/charts/{sha256} takes plus its sha256. Known charts keep their notes and metadata,
 * fill in metadata they lack (English titles), and are marked stock and ranked. --nijiiro-songs adds the
 * songs Nijiiro shipped outside the exported install (removed ones, later ones; the repo keeps them in
 * database/data/nijiiro-song-ids.txt): every chart of those song ids the exports lack is marked stock
 * and ranked too (an exported song's other charts stay custom). --unrank-custom then unranks every
 * chart not marked stock, admin-ranked ones too (their scores stay, out of the standings), after a
 * confirmation that --force skips.
 */
#[Signature('app:import-waddamburo-charts
    {files* : The files written by Waddamburo --export-charts=FILE on unmodified installs}
    {--nijiiro-songs= : A file of song ids (one per line, # comments) that were on the Nijiiro cabinet at some point}
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
        $songsPath = $this->option('nijiiro-songs');
        $songIds = $songsPath === null ? [] : (is_readable($songsPath) ? file($songsPath, FILE_IGNORE_NEW_LINES) : false);
        if ($songIds === false) {
            $this->error("Cannot read {$songsPath}.");

            return self::FAILURE;
        }
        $songIds = array_filter(array_map(fn (string $line): string => trim(explode('#', $line, 2)[0]), $songIds));
        $now = now();
        $imported = 0;
        $skipped = 0;
        $rows = [];
        $links = [];
        $exportedSongs = [];
        $flush = function () use (&$rows, &$links, &$exportedSongs): void {
            $keep = fn (string $column): Expression => DB::raw(
                "CASE WHEN wdb_charts.notes IS NULL THEN excluded.{$column} ELSE wdb_charts.{$column} END");
            DB::table('wdb_charts')->upsert(array_values($rows), ['sha256'], [
                'notes' => $keep('notes'), 'title' => $keep('title'), 'subtitle' => $keep('subtitle'),
                'source' => $keep('source'), 'course' => $keep('course'), 'level' => $keep('level'),
                'is_stock' => DB::raw('true'),
                'ranked_at' => DB::raw('COALESCE(wdb_charts.ranked_at, excluded.ranked_at)'),
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
            $this->importLines($file, $rows, $links, $imported, $skipped, $now, $flush);
            fclose($file);
        }
        if ($rows !== []) {
            $flush();
        }
        $songCharts = 0;
        // A song the exports ship is judged by its exact charts; the list only adds songs they lack.
        $listedSongs = array_values(array_filter(array_unique($songIds),
            fn (string $songId): bool => ! isset($exportedSongs[$songId])));
        foreach (array_chunk($listedSongs, 1000) as $chunk) {
            $charts = fn () => WdbChart::query()
                ->whereIn('id', DB::table('wdb_chart_song')->select('wdb_chart_id')
                    ->whereIn('wdb_song_id', DB::table('wdb_songs')->select('id')
                        ->where('family', WaddamburoSongs::family('Nijiiro'))->whereIn('song_key', $chunk)));
            $songCharts += $charts()->update(['is_stock' => true]);
            $charts()->whereNull('ranked_at')->update(['ranked_at' => $now]);
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

        $this->info("Imported and ranked {$imported} charts ({$skipped} invalid lines skipped).");
        if ($songsPath !== null) {
            $this->info("Ranked {$songCharts} charts of ".count($listedSongs).' Nijiiro song ids the exports lack.');
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
     */
    private function importLines($file, array &$rows, array &$links, int &$imported, int &$skipped, CarbonInterface $now, Closure $flush): void
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

            // Keyed by hash: Postgres refuses an upsert touching the same row twice.
            $rows[$sha256] = [
                'sha256' => $sha256,
                'notes' => '\x'.bin2hex($compressed),
                'title' => isset($chart['title']) ? mb_substr((string) $chart['title'], 0, 255) : null,
                'subtitle' => isset($chart['subtitle']) ? mb_substr((string) $chart['subtitle'], 0, 255) : null,
                'source' => isset($chart['source']) ? mb_substr((string) $chart['source'], 0, 255) : null,
                'is_stock' => true,
                'course' => isset($chart['course']) ? (int) $chart['course'] : null,
                'level' => isset($chart['level']) ? (int) $chart['level'] : null,
                'title_en' => isset($chart['title_en']) ? mb_substr((string) $chart['title_en'], 0, 255) : null,
                'subtitle_en' => isset($chart['subtitle_en']) ? mb_substr((string) $chart['subtitle_en'], 0, 255) : null,
                'difficulty' => isset($chart['difficulty']) ? mb_substr((string) $chart['difficulty'], 0, 255) : null,
                'osu_beatmap_id' => isset($chart['osu_beatmap_id']) && (int) $chart['osu_beatmap_id'] > 0 ? (int) $chart['osu_beatmap_id'] : null,
                'osu_beatmapset_id' => isset($chart['osu_beatmapset_id']) && (int) $chart['osu_beatmapset_id'] > 0 ? (int) $chart['osu_beatmapset_id'] : null,
                'ranked_at' => $now,
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
