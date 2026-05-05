<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['baid', 'token_id', 'count'])]
class Token extends Model
{
    //
}
