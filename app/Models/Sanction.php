<?php

namespace App\Models;

use App\Enums\SanctionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ban, shadowban or mute on a player (by baid: plays, cabinets and Waddamburo identity all go by it).
 * Rows are never deleted; a player is sanctioned while a row of that type is unrevoked and unexpired.
 */
#[Fillable(['baid', 'type', 'reason', 'note_to_player', 'expires_at', 'created_by', 'revoked_at', 'revoked_by'])]
class Sanction extends Model
{
    protected function casts(): array
    {
        return [
            'type' => SanctionType::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** The active sanction of that type on the player, if any (the longest-running one). */
    public static function activeFor(int $baid, SanctionType $type): ?self
    {
        return self::query()->active()->where('baid', $baid)->where('type', $type)
            ->orderByRaw('expires_at IS NULL DESC')->orderByDesc('expires_at')->first();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(fn (Builder $live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * The account standing a player sees on their own board: their sanctions, newest first, never a
     * shadowban. Null for anyone else.
     *
     * @return list<array{id: int, type: string, label: string, note: ?string, created_at: ?string, expires_at: ?string, revoked_at: ?string, active: bool}>|null
     */
    public static function standingFor(User $owner, ?User $viewer): ?array
    {
        if ($viewer === null || ! $owner->is($viewer) || $owner->player === null) {
            return null;
        }

        return self::query()->where('baid', $owner->player->baid)->where('type', '<>', SanctionType::Shadowban)->latest('id')->get()
            ->map(fn (self $sanction): array => [
                'id' => $sanction->id,
                'type' => $sanction->type->value,
                'label' => $sanction->type === SanctionType::Ban ? 'Restriction' : $sanction->type->label(),
                'note' => $sanction->note_to_player,
                'created_at' => $sanction->created_at?->toIso8601String(),
                'expires_at' => $sanction->expires_at?->toIso8601String(),
                'revoked_at' => $sanction->revoked_at?->toIso8601String(),
                'active' => $sanction->isActive(),
            ])->all();
    }

    /** What a banned player is told: until when, and the staff's note to them. */
    public function banMessage(): string
    {
        $until = $this->expires_at === null ? 'permanently' : 'until '.$this->expires_at->utc()->toDayDateTimeString().' UTC';

        return trim("Your account is restricted {$until}: your plays don't count and other players can't see them. ".($this->note_to_player ?? ''));
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
