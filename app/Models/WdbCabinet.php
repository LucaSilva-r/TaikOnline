<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A cabinet let in to send Waddamburo plays: its token (shown once, only the hash kept) logs it in as a
 * cabinet. Issued by an admin or by a player for their own cabinet (user_id). It stops working while
 * disabled, once revoked (for good), or while its owner is banned.
 */
#[Fillable(['name', 'token_hash', 'user_id', 'last_seen_at', 'disabled_at', 'revoked_at', 'created_by'])]
#[Hidden(['token_hash'])]
class WdbCabinet extends Model
{
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'disabled_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * A new cabinet and its token (the only time the token exists in the clear).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?int $createdBy, ?int $ownerId = null): array
    {
        $token = Str::random(48);
        $cabinet = self::query()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'user_id' => $ownerId,
            'created_by' => $createdBy,
        ]);

        return [$cabinet, $token];
    }

    /** The working cabinet a token belongs to, if any: not revoked, not disabled, owner not banned. */
    public static function forToken(string $token): ?self
    {
        $cabinet = self::query()->with('user.player')->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->whereNull('disabled_at')->first();

        return $cabinet?->ownerBanned() ? null : $cabinet;
    }

    public function ownerBanned(): bool
    {
        return $this->user?->player?->activeBan() !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plays(): HasMany
    {
        return $this->hasMany(WdbPlay::class);
    }
}
