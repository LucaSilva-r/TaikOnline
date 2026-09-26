<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Waddamburo play; the id is the client's, so re-uploads are idempotent. */
#[Fillable([
    'id', 'baid', 'wdb_chart_id', 'mode', 'course', 'score', 'great', 'good', 'miss', 'max_combo',
    'rolls', 'gauge', 'cleared', 'scoring_version', 'engine_version', 'played_at', 'replay',
])]
#[Hidden(['replay'])]
class WdbPlay extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'cleared' => 'boolean',
            'played_at' => 'datetime',
            'replay' => PostgresBytea::class,
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }

    public function chart(): BelongsTo
    {
        return $this->belongsTo(WdbChart::class, 'wdb_chart_id');
    }
}
