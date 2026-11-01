<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The email of an account deleted while banned, as a keyed hash, so the same person cannot sign up
 * again to shake the ban off. It lasts as long as the ban would have. See docs/features/moderation.md.
 */
#[Fillable(['hash', 'expires_at'])]
class BlockedEmail extends Model
{
    use MassPrunable;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function block(string $email, ?\DateTimeInterface $until): void
    {
        self::query()->updateOrCreate(['hash' => self::hashOf($email)], ['expires_at' => $until]);
    }

    public static function isBlocked(string $email): bool
    {
        return self::query()->where('hash', self::hashOf($email))
            ->where(fn (Builder $live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }

    /** Keyed, so a list of addresses cannot be tried against it without the app key. */
    private static function hashOf(string $email): string
    {
        return hash_hmac('sha256', self::normalized($email), (string) config('app.key'));
    }

    /** The same mailbox written differently counts as one: case, a +tag, and Gmail's dots. */
    private static function normalized(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', Str::lower(trim($email)), 2), 2, '');
        $local = Str::before($local, '+');
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            return str_replace('.', '', $local).'@gmail.com';
        }

        return "{$local}@{$domain}";
    }
}
