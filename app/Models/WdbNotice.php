<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A system notice for every Waddamburo client (severity info, warning or critical). */
#[Fillable(['message', 'severity', 'starts_at', 'ends_at', 'created_by'])]
class WdbNotice extends Model
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $starts) => $starts->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** @return array{id: string, message: string, severity: string, starts_at: ?string, ends_at: ?string, created_at: ?string} as clients get it (ids as strings, like personal notices' uuids) */
    public function toClient(): array
    {
        return [
            'id' => (string) $this->id, 'message' => $this->message, 'severity' => $this->severity,
            'starts_at' => $this->starts_at?->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
