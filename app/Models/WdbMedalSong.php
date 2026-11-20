<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One finished Waddamburo song, counted towards a Don Medal credit; the id is the client's play id. */
#[Fillable(['id', 'baid', 'credit_id', 'kind', 'chart_sha256', 'side', 'select_seconds', 'local_day', 'played_at'])]
class WdbMedalSong extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['local_day' => 'immutable_date', 'played_at' => 'immutable_datetime'];
    }
}
