<?php

namespace App\Models;

use App\Enums\UploadWarningKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bad uploads of one kind by one player (no baid: a cabinet or an account without a Banapass) in one
 * day, counted, so a flood of them stays one row. See WaddamburoUploadWarnings.
 */
#[Fillable(['baid', 'kind', 'day', 'count', 'play_id', 'detail', 'last_seen_at'])]
class WdbUploadWarning extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => UploadWarningKind::class,
            'day' => 'date:Y-m-d',
            'count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
