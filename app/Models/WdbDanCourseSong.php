<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One song of a dan course: the game's song id and the course played (0 easy .. 4 ura), as on wdb_charts. */
#[Fillable(['position', 'song_key', 'course', 'notes'])]
class WdbDanCourseSong extends Model {}
