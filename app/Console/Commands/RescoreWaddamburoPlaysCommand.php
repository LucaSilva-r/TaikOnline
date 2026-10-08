<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\WdbPlay;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scores Waddamburo plays from their replays with the installed scorer: the plays not scored yet, or
 * with --all every play (after a rules change ships in a new Waddamburo binary). The standings follow.
 * Reports what moved: plays scored and failed, scores changed, the largest changes.
 */
#[Signature('app:rescore-waddamburo-plays {--all : Rescore every play, not only the ones never scored}')]
#[Description('Score Waddamburo plays from their replays with the current rules')]
class RescoreWaddamburoPlaysCommand extends Command
{
    /** Plays per scorer run. */
    private const CHUNK = 500;

    public function handle(WaddamburoScorer $scorer, WaddamburoRankAggregateService $aggregates): int
    {
        $query = WdbPlay::query()->when(! $this->option('all'), fn ($query) => $query->whereNull('rescored_at'));
        $bar = $this->output->createProgressBar((clone $query)->count());
        $players = [];
        $scored = 0;
        $failed = 0;
        $changes = [];
        $query->chunkById(self::CHUNK, function (Collection $plays) use ($scorer, $bar, &$players, &$scored, &$failed, &$changes): void {
            $summary = $scorer->rescore($plays);
            $players = [...$players, ...$summary['players']];
            $scored += $summary['scored'];
            $failed += $summary['failed'];
            $changes = [...$changes, ...$summary['changes']];
            $bar->advance($plays->count());
        });
        $bar->finish();
        $this->newLine(2);

        $players = array_values(array_unique($players));
        $this->info('Updating '.count($players).' players\' standings...');
        Player::query()->whereIn('baid', $players)->each(fn (Player $player) => $aggregates->recompute($player));

        $waiting = WdbPlay::query()->whereNull('rescored_at')
            ->whereHas('chart', fn ($chart) => $chart->whereNull('notes'))->count();
        $this->table(['', 'Plays'], [
            ['Scored', $scored],
            ['Score changed', count($changes)],
            ['  went up', count(array_filter($changes, fn (array $change): bool => $change['to'] > $change['from']))],
            ['  went down', count(array_filter($changes, fn (array $change): bool => $change['to'] < $change['from']))],
            ['Failed to score (see the log)', $failed],
            ['Waiting for their chart', $waiting],
        ]);
        if ($changes !== []) {
            usort($changes, fn (array $a, array $b): int => abs($b['to'] - $b['from']) <=> abs($a['to'] - $a['from']));
            $this->info('Largest changes:');
            $this->table(['Play', 'Baid', 'Was', 'Now', 'Change'], array_map(fn (array $change): array => [
                $change['play'], $change['baid'], number_format($change['from']), number_format($change['to']),
                sprintf('%+d', $change['to'] - $change['from']),
            ], array_slice($changes, 0, 10)));
        }
        if (! is_executable((string) config('services.waddamburo.scorer'))) {
            $this->error('WADDAMBURO_SCORER is not set to an executable: nothing was scored.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
