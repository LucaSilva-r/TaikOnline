<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A cabinet an admin let in: its token (shown once, only the hash kept) logs it in as a cabinet, until
 * revoked.
 */
#[Fillable(['name', 'token_hash', 'last_seen_at', 'revoked_at', 'created_by'])]
#[Hidden(['token_hash'])]
class WdbCabinet extends Model
{
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * A new cabinet and its token (the only time the token exists in the clear).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?int $createdBy): array
    {
        $token = Str::random(48);
        $cabinet = self::query()->create(['name' => $name, 'token_hash' => hash('sha256', $token), 'created_by' => $createdBy]);

        return [$cabinet, $token];
    }

    /** The unrevoked cabinet a token belongs to, if any. */
    public static function forToken(string $token): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    public function plays(): HasMany
    {
        return $this->hasMany(WdbPlay::class);
    }
}
