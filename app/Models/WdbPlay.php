<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use App\Enums\SanctionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** One Waddamburo play; the id is the client's, so re-uploads are idempotent. */
#[Fillable([
    'id', 'baid', 'wdb_chart_id', 'mode', 'course', 'score', 'great', 'good', 'miss', 'max_combo',
    'rolls', 'gauge', 'cleared', 'scoring_version', 'engine_version', 'played_at', 'replay', 'audio_offset_ms', 'input_offset_ms',
    'options', 'seed', 'wdb_cabinet_id', 'rescored_at', 'hidden_at', 'hidden_by', 'hidden_reason', 'timeline',
])]
#[Hidden(['replay', 'timeline'])]
class WdbPlay extends Model
{
    use HasUuids;

    /** The 真打 option's bit in {@see $options}: those plays score on their own scale (their own board). */
    public const SHINUCHI = 2;

    /** Nijiiro's speeds, as Waddamburo keeps their index from bit 9 of {@see $options}. */
    private const SPEEDS = ['1.0', '1.1', '1.2', '1.3', '1.4', '1.5', '1.6', '1.7', '1.8', '1.9', '2.0', '2.5', '3.0', '3.5', '4.0'];

    /**
     * A play's options as short tags (as the game's English score list shows them): the speed, Hidden
     * (ドロン), Reversed (あべこべ), Random (きまぐれ) or Chaos (でたらめ), Shin-uchi (真打).
     *
     * @return list<string>
     */
    public static function optionLabels(int $options): array
    {
        // Older plays kept only the speed's range (x2/x3/x4 bits).
        $speed = $options >> 9 ?: match (true) {
            ($options & 16) !== 0 => 14,
            ($options & 8) !== 0 => 12,
            ($options & 4) !== 0 => 10,
            default => 0,
        };

        return array_values(array_filter([
            $speed > 0 ? (self::SPEEDS[$speed] ?? '4.0').'x' : null,
            ($options & 32) !== 0 ? 'Hidden' : null,
            ($options & 64) !== 0 ? 'Reversed' : null,
            ($options & 256) !== 0 ? 'Chaos' : (($options & 128) !== 0 ? 'Random' : null),
            ($options & self::SHINUCHI) !== 0 ? 'Shin-uchi' : null,
        ]));
    }

    /**
     * The plays a viewer may see (null: nobody's own): never a hidden play, and a shadowbanned player's
     * plays only by that player. Every place that shows Waddamburo scores goes through this.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, ?int $viewerBaid): void
    {
        self::visibility($query, $viewerBaid);
    }

    /** {@see scopeVisibleTo()} on a plain query over wdb_plays. */
    public static function visibility(Builder|QueryBuilder $query, ?int $viewerBaid): void
    {
        $query->whereNull('wdb_plays.hidden_at')->where(fn ($visible) => $visible
            ->whereNotExists(fn (QueryBuilder $shadowban) => $shadowban->from('sanctions')
                ->whereColumn('sanctions.baid', 'wdb_plays.baid')
                ->where('sanctions.type', SanctionType::Shadowban->value)
                ->whereNull('sanctions.revoked_at')
                ->where(fn (QueryBuilder $live) => $live->whereNull('sanctions.expires_at')->orWhere('sanctions.expires_at', '>', now())))
            ->when($viewerBaid !== null, fn ($own) => $own->orWhere('wdb_plays.baid', $viewerBaid)));
    }

    protected function casts(): array
    {
        return [
            'cleared' => 'boolean',
            'played_at' => 'datetime',
            'rescored_at' => 'datetime',
            'hidden_at' => 'datetime',
            'replay' => PostgresBytea::class,
            'timeline' => PostgresBytea::class,
        ];
    }

    /**
     * The engine's judgement timeline (see WaddamburoScorer): every note's result and offset, every input and
     * what it hit, the roll hits. Null until a scorer that returns it rescored the play.
     *
     * @return array<string, mixed>|null
     */
    public function judgementTimeline(): ?array
    {
        $json = $this->timeline === null ? false : @gzdecode((string) $this->timeline);

        return $json === false ? null : json_decode($json, true);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }

    public function chart(): BelongsTo
    {
        return $this->belongsTo(WdbChart::class, 'wdb_chart_id');
    }
}
