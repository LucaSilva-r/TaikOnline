<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sha256', 'extra_song_id', 'difficulty', 'observed_title',
    'observed_source_id', 'first_seen_at', 'last_seen_at',
])]
class ExtraChart extends Model
{
    protected function casts(): array
    {
        return [
            'difficulty' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(ExtraSong::class, 'extra_song_id');
    }

    public function bests(): HasMany
    {
        return $this->hasMany(ExtraChartBest::class);
    }
}
