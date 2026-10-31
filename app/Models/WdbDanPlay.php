<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use App\Enums\SanctionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** A dan course's play, scored by the server from its songs' replays. */
class WdbDanPlay extends Model
{
    use HasUuids;

    /** The scorer's result levels. */
    public const FAILED = 0;

    public const RED = 1;

    public const GOLD = 2;

    protected $guarded = [];

    protected $hidden = ['progress'];

    protected function casts(): array
    {
        return ['played_at' => 'datetime', 'rescored_at' => 'datetime', 'hidden_at' => 'datetime', 'progress' => PostgresBytea::class];
    }

    /**
     * What a viewer may see: not hidden by staff, and not by a player whose sanction hides their plays,
     * unless it is their own. As {@see WdbPlay::visibility()}.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, ?int $viewerBaid): void
    {
        $query->whereNull('wdb_dan_plays.hidden_at')->where(fn ($visible) => $visible
            ->whereNotExists(fn (QueryBuilder $shadowban) => $shadowban->from('sanctions')
                ->whereColumn('sanctions.baid', 'wdb_dan_plays.baid')
                ->whereIn('sanctions.type', SanctionType::hidingPlays())
                ->whereNull('sanctions.revoked_at')
                ->where(fn (QueryBuilder $live) => $live->whereNull('sanctions.expires_at')->orWhere('sanctions.expires_at', '>', now())))
            ->when($viewerBaid !== null, fn ($own) => $own->orWhere('wdb_dan_plays.baid', $viewerBaid)));
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }

    /** @return HasMany<WdbDanPlaySong, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(WdbDanPlayReport::class);
    }

    public function songs(): HasMany
    {
        return $this->hasMany(WdbDanPlaySong::class)->orderBy('position');
    }
}
