<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'serial',
    'user_id',
    'nickname',
    'registered_at',
    'last_heartbeat_at',
    'last_ip',
])]
class Cabinet extends Model
{
    public const DEFAULT_SERIAL = '268410000000';

    public const SERIAL_PREFIX = '26841';

    protected $primaryKey = 'serial';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDefault(): bool
    {
        return $this->serial === self::DEFAULT_SERIAL;
    }

    public function isOnline(): bool
    {
        return $this->last_heartbeat_at !== null
            && $this->last_heartbeat_at->gt(now()->subSeconds(60));
    }
}
