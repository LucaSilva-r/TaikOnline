<?php

namespace App\Models;

use App\Enums\PlayReportReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A player's report on a dan run, for the moderation queue. */
class WdbDanPlayReport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reason' => PlayReportReason::class, 'resolved_at' => 'datetime'];
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @return BelongsTo<WdbDanPlay, $this> */
    public function play(): BelongsTo
    {
        return $this->belongsTo(WdbDanPlay::class, 'wdb_dan_play_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
