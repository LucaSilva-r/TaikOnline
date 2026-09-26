<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dan_course_id', 'song_no', 'level', 'sort_order'])]
class DanCourseSong extends Model
{
    public function course(): BelongsTo
    {
        return $this->belongsTo(DanCourse::class, 'dan_course_id');
    }
}
