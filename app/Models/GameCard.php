<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['access_code', 'baid', 'chip_id', 'device_type', 'country_id'])]
class GameCard extends Model
{
    protected $table = 'cards';

    protected $primaryKey = 'access_code';

    public $incrementing = false;

    protected $keyType = 'string';

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }
}
