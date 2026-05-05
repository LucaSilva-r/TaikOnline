<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['chassis_id', 'shop_id', 'baid', 'net_id', 'played_at', 'is_right', 'place_id', 'type', 'amount'])]
class HeadClerkLog extends Model
{
    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
            'is_right' => 'boolean',
        ];
    }
}
