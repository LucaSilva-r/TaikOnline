<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One moderation write (see ModerationService): who did what to which player or play, and why. */
#[Fillable(['actor_id', 'action', 'baid', 'wdb_play_id', 'sanction_id', 'reason'])]
class ModerationAction extends Model
{
    public const UPDATED_AT = null;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function sanction(): BelongsTo
    {
        return $this->belongsTo(Sanction::class);
    }
}
