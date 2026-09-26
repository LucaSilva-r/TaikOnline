<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dan dojo course (one challenge level slot) for a given version. Songs are
 * served to the cabinet; pass conditions live on the cabinet itself.
 */
#[Fillable(['version', 'dan', 'unique_id', 'name', 'difficulty', 'verup_no'])]
class DanCourse extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'string',
        ];
    }

    public function songs(): HasMany
    {
        return $this->hasMany(DanCourseSong::class)->orderBy('sort_order');
    }
}
