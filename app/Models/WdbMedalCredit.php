<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A Waddamburo credit that paid Don Medals (Green's 100 + daily 50 + weekly 100 + monthly 300). */
#[Fillable(['baid', 'local_day', 'season_id', 'medals', 'daily', 'weekly', 'monthly', 'announced', 'katsu'])]
class WdbMedalCredit extends Model
{
    protected function casts(): array
    {
        return [
            'local_day' => 'immutable_date',
            'daily' => 'boolean',
            'weekly' => 'boolean',
            'monthly' => 'boolean',
            'announced' => 'boolean',
        ];
    }
}
