<?php

namespace App\Models;

use App\Enums\PlayReportReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A player's report of a Waddamburo play: open until a moderator closes it (see ModerationService). */
#[Fillable(['wdb_play_id', 'reporter_id', 'reason', 'comment', 'resolved_at', 'resolved_by', 'resolution'])]
class PlayReport extends Model
{
    public const DISMISSED = 'dismissed';

    public const PLAY_HIDDEN = 'play_hidden';

    public const PLAYER_SANCTIONED = 'player_sanctioned';

    protected function casts(): array
    {
        return [
            'reason' => PlayReportReason::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function play(): BelongsTo
    {
        return $this->belongsTo(WdbPlay::class, 'wdb_play_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
