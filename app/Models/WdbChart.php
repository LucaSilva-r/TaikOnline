<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Waddamburo chart: its canonical parsed notes (gzipped, never served back) and display
 * metadata. Unranked until an admin sets ranked_at.
 */
#[Fillable(['sha256', 'notes', 'title', 'subtitle', 'source', 'course', 'level', 'ranked_at'])]
#[Hidden(['notes'])]
class WdbChart extends Model
{
    protected function casts(): array
    {
        return [
            'notes' => PostgresBytea::class,
            'ranked_at' => 'datetime',
        ];
    }

    public function plays(): HasMany
    {
        return $this->hasMany(WdbPlay::class);
    }
}
