<?php

namespace App\Console\Commands;

use App\Http\Controllers\WaddamburoController;
use App\Models\Player;
use App\Services\WaddamburoRankAggregateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Imports Waddamburo's own charts (stock and Nijiiro, from the client's --export-charts) as ranked:
 * gzipped JSON lines, each the import PUT api/wdb/charts/{sha256} takes plus its sha256. Known charts
 * keep their notes and metadata and only get ranked.
 */
#[Signature('app:import-waddamburo-charts {file : The file written by Waddamburo --export-charts=FILE}')]
#[Description('Import and rank every stock and Nijiiro Waddamburo chart by hash')]
class ImportWaddamburoChartsCommand extends Command
{
    public function handle(WaddamburoRankAggregateService $aggregates): int
    {
        $path = (string) $this->argument('file');
        $file = is_readable($path) ? fopen('compress.zlib://'.$path, 'r') : false;
        if ($file === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }
        $now = now();
        $imported = 0;
        $skipped = 0;
        $rows = [];
        $flush = function () use (&$rows): void {
            $keep = fn (string $column): Expression => DB::raw(
                "CASE WHEN wdb_charts.notes IS NULL THEN excluded.{$column} ELSE wdb_charts.{$column} END");
            DB::table('wdb_charts')->upsert(array_values($rows), ['sha256'], [
                'notes' => $keep('notes'), 'title' => $keep('title'), 'subtitle' => $keep('subtitle'),
                'source' => $keep('source'), 'course' => $keep('course'), 'level' => $keep('level'),
                'ranked_at' => DB::raw('COALESCE(wdb_charts.ranked_at, excluded.ranked_at)'),
                'updated_at' => DB::raw('excluded.updated_at'),
            ]);
            $rows = [];
        };

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
                'course' => isset($chart['course']) ? (int) $chart['course'] : null,
                'level' => isset($chart['level']) ? (int) $chart['level'] : null,
                'ranked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $imported++;
            if (count($rows) >= 500) {
                $flush();
            }
        }
        fclose($file);
        if ($rows !== []) {
            $flush();
        }

        Player::query()
            ->whereIn('baid', DB::table('wdb_plays')->select('baid')->distinct())
            ->each(fn (Player $player) => $aggregates->recompute($player));

        $this->info("Imported and ranked {$imported} charts ({$skipped} invalid lines skipped).");

        return self::SUCCESS;
    }
}
