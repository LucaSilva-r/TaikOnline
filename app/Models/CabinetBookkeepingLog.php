<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'chassis_id',
    'shop_id',
    'update_date',
    'all_play_count',
    'service_switch_count',
    'free_play_count',
    'payload',
])]
class CabinetBookkeepingLog extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
