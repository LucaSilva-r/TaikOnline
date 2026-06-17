<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'username', 'email', 'password', 'role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Always expose the computed avatar URL so shared auth.user and any serialized User
     * carries it to the frontend.
     *
     * @var list<string>
     */
    protected $appends = ['avatar'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'avatar_updated_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    /**
     * Public URL of the generated Don-chan avatar, or null when none has been
     * created. The query string busts caches whenever the avatar is regenerated.
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->avatar_updated_at === null) {
                return null;
            }

            // Root-relative so it resolves against whatever host serves the page, rather
            // than the absolute APP_URL the public disk would otherwise prepend.
            return "/storage/avatars/{$this->id}.png?v={$this->avatar_updated_at->timestamp}";
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function player(): HasOne
    {
        return $this->hasOne(Player::class);
    }

    public function rankSnapshots(): HasMany
    {
        return $this->hasMany(PlayerRankSnapshot::class);
    }
}
