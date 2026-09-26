<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'subtitle', 'edition', 'is_ranked'])]
class ExtraSong extends Model
{
    protected function casts(): array
    {
        return ['is_ranked' => 'boolean'];
    }

    public function charts(): HasMany
    {
        return $this->hasMany(ExtraChart::class)->orderBy('difficulty');
    }
}
