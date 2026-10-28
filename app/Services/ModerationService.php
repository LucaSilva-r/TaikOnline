<?php

namespace App\Services;

use App\Enums\SanctionType;
use App\Models\ModerationAction;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbPlay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every moderation write (docs/features/moderation.md): sanctions on a player, hiding a play from every
 * board. Each goes in the moderation_actions log with its actor and reason. Sanctions need no aggregate
 * recompute: the standings and boards filter shadowbanned players when they are read.
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
