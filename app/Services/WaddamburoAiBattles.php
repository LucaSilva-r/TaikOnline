<?php

namespace App\Services;

use App\Enums\SanctionType;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Notifications\WaddamburoNotice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Immutable server-issued battle setups and progression derived from verified replays. */
class WaddamburoAiBattles
{
    public const VERSION = 1;

    /** The shared Green rank requirements; ranks above 10 need the challenge-folder implementation. */
    private const STEPS = [1, 8, 15, 30, 45, 60, 75, 90, 105, 120, 150];

    private const LEAST = [[1, 1, 1, 1, 1], [3, 2, 1, 1, 1], [5, 4, 1, 1, 1], [0, 6, 3, 2, 2],
        [0, 7, 4, 3, 3], [0, 0, 6, 5, 5], [0, 0, 8, 7, 7], [0, 0, 0, 8, 8], [0, 0, 0, 9, 9],
        [0, 0, 0, 10, 10], [0, 0, 0, 99, 99]];

    private const BONUS = [[2, 2, 1, 1, 1], [4, 3, 1, 1, 1], [0, 5, 2, 1, 1], [0, 7, 4, 3, 3],
        [0, 0, 5, 4, 4], [0, 0, 7, 6, 6], [0, 0, 0, 8, 8], [0, 0, 0, 9, 9], [0, 0, 0, 10, 10],
        [0, 0, 0, 0, 0], [0, 0, 0, 0, 0]];

    /** @param array{chart_sha256: string, base_sha256: string, course: int, options: int, support_level: int, version?: int} $setup */
    public function begin(int $baid, array $setup): array
    {
        $setup['version'] = (int) ($setup['version'] ?? self::VERSION);

        return DB::transaction(function () use ($baid, $setup): array {
            Player::query()->whereKey($baid)->lockForUpdate()->firstOrFail();
            // Retrying or abandoning the same pick cannot be used to draw a more favourable seed.
            $session = DB::table('wdb_ai_sessions')->where('baid', $baid)->where($setup)
                ->whereNull('play_id')->orderByDesc('created_at')->first();
            if ($session === null) {
                $this->rebuild($baid);
                $progress = $this->progress($baid);
                $prior = $this->verified($baid)->where('eligible', true)->join('wdb_ai_sessions', 'wdb_ai_sessions.id', '=', 'wdb_ai_battles.session_id')
                    ->whereNotNull('wdb_ai_battles.hit_median')->whereNotNull('wdb_ai_battles.hit_variance')->orderByDesc('wdb_plays.played_at')
                    ->first(['wdb_ai_battles.hit_median', 'wdb_ai_battles.hit_variance']);
                $base = WdbChart::query()->where('sha256', $setup['base_sha256'])->first();
                $ranked = $base?->ranked_at !== null && Sanction::activeFor($baid, SanctionType::Ban) === null;
                $id = (string) Str::uuid();
                DB::table('wdb_ai_sessions')->insert([
                    'id' => $id, 'baid' => $baid, 'sequence' => 1 + (int) DB::table('wdb_ai_sessions')->where('baid', $baid)->max('sequence'), ...$setup, 'seed' => random_int(0, 2147483647),
                    'ranked' => $ranked, 'points' => $progress['points'], 'wins' => $progress['wins'],
                    'hit_median' => $prior?->hit_median, 'hit_variance' => $prior?->hit_variance,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $session = DB::table('wdb_ai_sessions')->where('id', $id)->first();
            }

            return ['id' => $session->id, 'seed' => (int) $session->seed, 'version' => (int) $session->version,
                'chart_sha256' => $session->chart_sha256, 'course' => (int) $session->course, 'options' => (int) $session->options,
                'support_level' => (int) $session->support_level, 'ranked' => (bool) $session->ranked,
                'points' => (int) $session->points, 'wins' => (int) $session->wins,
                'hit_median_ms' => $session->hit_median === null ? null : (float) $session->hit_median,
                'hit_variance' => $session->hit_variance === null ? null : (float) $session->hit_variance];
        });
    }

    /** Bind a setup to exactly one upload, after its replay has passed structural validation. */
    public function reserve(int $baid, array $play): ?string
    {
        if (($play['ai_session_id'] ?? null) === null) {
            return $this->offline($baid, $play);
        }

        return DB::transaction(function () use ($baid, $play): string {
            $session = DB::table('wdb_ai_sessions')->where('id', $play['ai_session_id'])->lockForUpdate()->first();
            if ($session === null || (int) $session->baid !== $baid || $play['mode'] !== 'ai_battle'
                || $session->chart_sha256 !== $play['chart_sha256'] || (int) $session->course !== (int) $play['course']
                || (int) $session->options !== (int) ($play['options'] ?? 0)
                || ! isset($play['seed']) || (int) $session->seed !== (int) $play['seed']
                || (int) $session->support_level !== (int) ($play['ai_support'] ?? 0)
                || ($session->play_id !== null && $session->play_id !== $play['id'])) {
                throw ValidationException::withMessages(['ai_session_id' => 'This battle does not match its server-issued setup.']);
            }
            if ($session->play_id !== $play['id'] && WdbPlay::query()->whereKey($play['id'])->exists()) {
                throw ValidationException::withMessages(['ai_session_id' => 'This play already belongs to a different battle setup.']);
            }
            DB::table('wdb_ai_sessions')->where('id', $session->id)->update(['play_id' => $play['id'], 'updated_at' => now()]);

            return (string) $session->id;
        });
    }

    /**
     * A battle played without a server setup (offline): its setup is what the client played with, created on upload and
     * verified the same way. ponytail: the client's starting timing is trusted (range-checked); with AI-don perfect
     * until the player's first slip, it only matters for a slip within the first few hits.
     */
    private function offline(int $baid, array $play): ?string
    {
        if ($play['mode'] !== 'ai_battle' || ! isset($play['seed'])) {
            return null;
        }

        return DB::transaction(function () use ($baid, $play): ?string {
            Player::query()->whereKey($baid)->lockForUpdate()->firstOrFail();
            $existing = DB::table('wdb_ai_sessions')->where('play_id', $play['id'])->first();
            if ($existing !== null) {
                return (int) $existing->baid === $baid ? (string) $existing->id : null;
            }
            if (WdbPlay::query()->whereKey($play['id'])->exists()) {
                return null;
            }
            $support = (int) ($play['ai_support'] ?? 0);
            $base = $support === 0 ? $play['chart_sha256'] : ($play['ai_base_sha256'] ?? null);
            if ($base === null) {
                return null;
            }
            $progress = $this->progress($baid);
            $id = (string) Str::uuid();
            DB::table('wdb_ai_sessions')->insert([
                'id' => $id, 'baid' => $baid, 'sequence' => 1 + (int) DB::table('wdb_ai_sessions')->where('baid', $baid)->max('sequence'),
                'chart_sha256' => $play['chart_sha256'], 'base_sha256' => $base, 'course' => (int) $play['course'],
                'options' => (int) ($play['options'] ?? 0), 'support_level' => $support, 'version' => self::VERSION,
                'seed' => (int) $play['seed'],
                'ranked' => WdbChart::query()->where('sha256', $base)->whereNotNull('ranked_at')->exists()
                    && Sanction::activeFor($baid, SanctionType::Ban) === null,
                'points' => $progress['points'], 'wins' => $progress['wins'],
                'hit_median' => isset($play['ai_prior_median_ms'], $play['ai_prior_variance']) ? (float) $play['ai_prior_median_ms'] : null,
                'hit_variance' => isset($play['ai_prior_median_ms'], $play['ai_prior_variance']) ? (float) $play['ai_prior_variance'] : null,
                'play_id' => $play['id'], 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    /** The scorer's trusted context. Nothing supplied by the upload can override these values. */
    public function context(WdbPlay $play): ?array
    {
        $session = DB::table('wdb_ai_sessions')->where('play_id', $play->id)->where('baid', $play->baid)->first();
        if ($play->mode !== 'ai_battle' || $session === null || $session->chart_sha256 !== $play->chart->sha256
            || (int) $session->seed !== (int) $play->seed || (int) $session->course !== (int) $play->course
            || (int) $session->options !== (int) $play->options) {
            return null;
        }
        $base = WdbChart::query()->where('sha256', $session->base_sha256)->first();

        return ['version' => (int) $session->version, 'support_level' => (int) $session->support_level,
            'base_notes' => $base?->notes === null ? null : base64_encode((string) $base->notes),
            'base_source' => $base?->source,
            'hit_median_ms' => $session->hit_median === null ? null : (float) $session->hit_median,
            'hit_variance' => $session->hit_variance === null ? null : (float) $session->hit_variance];
    }

    /** Store the simulator's result and rebuild awards in setup order, including after a rescore or delayed upload. */
    public function apply(WdbPlay $play, ?array $result): void
    {
        if ($play->mode !== 'ai_battle') {
            return;
        }
        $rejection = DB::transaction(function () use ($play, $result): ?string {
            Player::query()->whereKey($play->baid)->lockForUpdate()->firstOrFail();
            $session = DB::table('wdb_ai_sessions')->where('play_id', $play->id)->where('baid', $play->baid)->first();
            $sections = $result['sections'] ?? null;
            $valid = $session !== null && (int) $session->course === (int) $play->course && (int) $session->version >= 1 && (int) $session->version <= self::VERSION && ($result['version'] ?? null) === (int) $session->version && is_string($sections)
                && preg_match('/\A(?:[WL]{3}|[WL]{5})\z/', $sections) === 1;
            if (! $valid) {
                DB::table('wdb_ai_battles')->where('id', $play->id)->update(['verified_at' => null, 'eligible' => false, 'points' => 0]);
                $this->rebuild((int) $play->baid);

                return 'Your AI battle could not be verified. Its provisional AI rank points and win were not added.';
            }
            $claimed = DB::table('wdb_ai_battles')->where('id', $play->id)->first(['won', 'points']);
            $won = substr_count($sections, 'W') * 2 > strlen($sections);
            $base = $session === null ? null : WdbChart::query()->where('sha256', $session->base_sha256)->first();
            DB::table('wdb_ai_battles')->where('id', $play->id)->update([
                'verified_at' => $valid ? now() : null,
                'eligible' => $valid && $session->ranked && $base?->ranked_at !== null
                    && Sanction::activeFor((int) $play->baid, SanctionType::Ban) === null,
                'won' => $valid && substr_count($sections, 'W') * 2 > strlen($sections),
                'sections' => $valid ? $sections : '', 'points' => 0,
                'chart_level' => $valid ? (int) ($result['level'] ?? 0) : null,
                'section_counts' => $valid ? json_encode($result['counts'] ?? [], JSON_THROW_ON_ERROR) : null,
                'hit_median' => $valid ? ($result['hit_median_ms'] ?? null) : null,
                'hit_variance' => $valid ? ($result['hit_variance'] ?? null) : null,
                'updated_at' => now(),
            ]);
            $this->rebuild((int) $play->baid);
            if ($claimed?->won && ! $won) {
                return 'Your AI battle was verified as a loss. Its provisional AI rank points and win were not added.';
            }
            if ($won && ! $play->cleared && (int) ($claimed?->points ?? 0) > 0) {
                return 'Your AI battle win was accepted, but the replay did not clear the gauge. No AI rank points were added.';
            }

            return null;
        });
        if ($rejection !== null) {
            $this->verificationFailed($play, $rejection);
        }
    }

    /** Warn once per ranked setup; offline, legacy and custom battles never promised online progress. */
    public function verificationFailed(WdbPlay $play, string $message = 'Your AI battle could not be verified. Its provisional AI rank points and win were not added.'): void
    {
        if ($play->mode !== 'ai_battle' || ! DB::table('wdb_ai_sessions')->where('play_id', $play->id)
            ->where('baid', $play->baid)->where('ranked', true)->exists()) {
            return;
        }
        $user = $play->player?->user;
        if ($user === null || $user->notifications()->where('type', WaddamburoNotice::class)
            ->where('data', 'like', '%"kind":"play_rejected"%')
            ->where('data', 'like', '%"play_id":"'.$play->id.'"%')->exists()) {
            return;
        }
        $user->notify(new WaddamburoNotice($message, 'warning', 'play_rejected', ['play_id' => $play->id]));
    }

    public function recompute(int $baid): void
    {
        DB::transaction(function () use ($baid): void {
            Player::query()->whereKey($baid)->lockForUpdate()->firstOrFail();
            $this->rebuild($baid);
        });
    }

    public function verified(int $baid): Builder
    {
        return DB::table('wdb_ai_battles')->join('wdb_plays', 'wdb_plays.id', '=', 'wdb_ai_battles.id')
            ->where('wdb_ai_battles.baid', $baid)->whereNotNull('wdb_ai_battles.verified_at')
            ->tap(fn (Builder $query) => WdbPlay::visibility($query, $baid));
    }

    /** @return array{points: int, wins: int, rank: int, points_to_next: int} */
    public function progress(int $baid, bool $public = false): array
    {
        $query = $this->verified($baid)->where('eligible', true);
        if ($public) {
            WdbPlay::visibility($query, null);
        }
        $totals = $query->selectRaw('COALESCE(SUM(points), 0) AS points, COALESCE(SUM(CASE WHEN won THEN 1 ELSE 0 END), 0) AS wins')->first();
        $points = (int) $totals->points;
        $rank = self::rank($points);

        return ['points' => $points, 'wins' => (int) $totals->wins, 'rank' => $rank,
            'points_to_next' => $rank >= count(self::STEPS) ? 0 : array_sum(array_slice(self::STEPS, 0, $rank + 1)) - $points];
    }

    private static function rank(int $points): int
    {
        $total = 0;
        foreach (self::STEPS as $rank => $step) {
            $total += $step;
            if ($points < $total) {
                return $rank;
            }
        }

        return count(self::STEPS);
    }

    private function rebuild(int $baid): void
    {
        $battles = $this->verified($baid)->join('wdb_ai_sessions', 'wdb_ai_sessions.id', '=', 'wdb_ai_battles.session_id')
            // Play order, not setup order: an offline battle's setup is only created when it uploads.
            ->where('eligible', true)->orderBy('wdb_plays.played_at')->orderBy('wdb_ai_sessions.sequence')
            ->get(['wdb_ai_battles.id', 'wdb_ai_battles.wdb_chart_id', 'won', 'cleared', 'wdb_ai_battles.support_level', 'chart_level', 'wdb_plays.course']);
        $points = 0;
        $won = [];
        foreach ($battles as $battle) {
            $rank = self::rank($points);
            $least = self::LEAST[$rank][$battle->course] ?? 0;
            $bonus = self::BONUS[$rank][$battle->course] ?? 0;
            $earned = $battle->won && $battle->cleared && (int) $battle->support_level === 0 && $least > 0 && $battle->chart_level >= $least
                ? 1 + (isset($won[$battle->wdb_chart_id]) ? 0 : 1) + ($bonus > 0 && $battle->chart_level >= $bonus ? 1 : 0) : 0;
            DB::table('wdb_ai_battles')->where('id', $battle->id)->update(['points' => $earned]);
            $points += $earned;
            if ($battle->won) {
                $won[$battle->wdb_chart_id] = true;
            }
        }
    }
}
