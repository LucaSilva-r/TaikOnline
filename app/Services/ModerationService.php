<?php

namespace App\Services;

use App\Enums\PlayReportReason;
use App\Enums\SanctionType;
use App\Models\ModerationAction;
use App\Models\Player;
use App\Models\PlayReport;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbPlay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Every moderation write (docs/features/moderation.md): sanctions on a player, hiding a play from every
 * board, players' reports and closing them. Each staff write goes in the moderation_actions log with its
 * actor and reason. Sanctions need no aggregate recompute: the standings and boards filter shadowbanned
 * players when they are read.
 */
class ModerationService
{
    public function __construct(private WaddamburoRankAggregateService $aggregates) {}

    /** Moderators act on players only; admins on anyone but themselves. */
    public static function canActOn(User $actor, Player $target): bool
    {
        $user = $target->user;

        return match (true) {
            $user?->is($actor) === true => false,
            $actor->isAdmin() => true,
            default => $actor->isStaff() && $user?->isStaff() !== true,
        };
    }

    /** Permanent bans are an admin's call; moderators issue timed ones. */
    public static function canIssue(User $actor, SanctionType $type, ?CarbonInterface $expiresAt): bool
    {
        return $actor->isAdmin() || $type !== SanctionType::Ban || $expiresAt !== null;
    }

    public function sanction(User $actor, Player $player, SanctionType $type, string $reason, ?string $noteToPlayer, ?CarbonInterface $expiresAt): Sanction
    {
        return DB::transaction(function () use ($actor, $player, $type, $reason, $noteToPlayer, $expiresAt): Sanction {
            $sanction = Sanction::query()->create([
                'baid' => $player->baid,
                'type' => $type,
                'reason' => $reason,
                'note_to_player' => $noteToPlayer,
                'expires_at' => $expiresAt,
                'created_by' => $actor->id,
            ]);
            $this->log($actor, $type->value, $player->baid, reason: $reason, sanction: $sanction);
            // A ban or shadowban answers every open report on the player's plays.
            if ($type === SanctionType::Ban || $type === SanctionType::Shadowban) {
                $this->resolve($actor, PlayReport::query()->whereHas('play', fn (Builder $play) => $play->where('baid', $player->baid)), PlayReport::PLAYER_SANCTIONED);
            }

            return $sanction;
        });
    }

    public function revoke(User $actor, Sanction $sanction, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $sanction, $reason): void {
            $sanction->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
            $this->log($actor, 'revoke_'.$sanction->type->value, $sanction->baid, reason: $reason, sanction: $sanction);
        });
    }

    public function hide(User $actor, WdbPlay $play, string $reason): void
    {
        DB::transaction(function () use ($actor, $play, $reason): void {
            $play->update(['hidden_at' => now(), 'hidden_by' => $actor->id, 'hidden_reason' => $reason]);
            $this->log($actor, 'hide_play', $play->baid, play: $play, reason: $reason);
            $this->resolve($actor, PlayReport::query()->where('wdb_play_id', $play->id), PlayReport::PLAY_HIDDEN);
        });
        $this->recompute($play);
    }

    public function restore(User $actor, WdbPlay $play, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $play, $reason): void {
            $play->update(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null]);
            $this->log($actor, 'restore_play', $play->baid, play: $play, reason: $reason);
        });
        $this->recompute($play);
    }

    /**
     * A player's report of a play (one per play and reporter: a second one changes nothing). Refused for
     * the reporter's own plays, plays they cannot see, and players blocked from reporting.
     *
     * @return bool whether a new report was filed
     */
    public function report(User $reporter, WdbPlay $play, PlayReportReason $reason, ?string $comment): bool
    {
        $baid = $reporter->player?->baid;
        if ($baid === null) {
            throw new HttpException(403, 'Your account does not have a Banapass yet.');
        }
        if ((int) $play->baid === (int) $baid) {
            throw new HttpException(422, 'You cannot report your own play.');
        }
        if (Sanction::activeFor((int) $baid, SanctionType::ReportBlock) !== null) {
            throw new HttpException(403, 'You cannot report plays.');
        }
        if (! WdbPlay::query()->whereKey($play->id)->visibleTo((int) $baid)->exists()) {
            throw new HttpException(404);
        }

        return PlayReport::query()->firstOrCreate(
            ['wdb_play_id' => $play->id, 'reporter_id' => $reporter->id],
            ['reason' => $reason, 'comment' => $comment],
        )->wasRecentlyCreated;
    }

    /** Closes a play's open reports with no action taken. */
    public function dismissReports(User $actor, WdbPlay $play, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $play, $reason): void {
            if ($this->resolve($actor, PlayReport::query()->where('wdb_play_id', $play->id), PlayReport::DISMISSED) > 0) {
                $this->log($actor, 'dismiss_reports', $play->baid, play: $play, reason: $reason);
            }
        });
    }

    /**
     * @param  Builder<PlayReport>  $reports
     * @return int how many open reports it closed
     */
    private function resolve(User $actor, Builder $reports, string $resolution): int
    {
        return $reports->open()->update(['resolved_at' => now(), 'resolved_by' => $actor->id, 'resolution' => $resolution]);
    }

    private function recompute(WdbPlay $play): void
    {
        if ($play->player !== null) {
            $this->aggregates->recompute($play->player);
        }
    }

    private function log(User $actor, string $action, int $baid, ?WdbPlay $play = null, ?string $reason = null, ?Sanction $sanction = null): void
    {
        ModerationAction::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'baid' => $baid,
            'wdb_play_id' => $play?->id,
            'sanction_id' => $sanction?->id,
            'reason' => $reason,
        ]);
    }
}
