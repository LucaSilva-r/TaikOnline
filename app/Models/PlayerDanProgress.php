<?php

namespace App\Models;

use App\Casts\PostgresBytea;
use App\Enums\TaikoGameVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version-scoped Taikojuku (dan dojo) progress for a player. Mirrors the AC15
 * dan state TaikoLocalServer keeps: a packed clear-grade bitfield, the highest
 * cleared normal dan, and the dan shown in the dojo menu.
 *
 * AC15 dan ids: normal 1..25 (extra dans 101..128 exist but are not echoed by
 * the supported protocol dialects, so only normal dans are tracked here).
 * Each dan occupies a 2-bit slot in got_dan_flg, LSB-first, packed by
 * (danId - 1). Wire grade values: 0 = not clear, 2 = clear, 3 = gold.
 */
class PlayerDanProgress extends Model
{
    public const MIN_NORMAL_DAN = 1;

    public const MAX_NORMAL_DAN = 25;

    /** Clear-grade enum values matching TaikoLocalServer's Ac15DanClearGrade. */
    public const GRADE_NOT_CLEAR = 0;

    public const GRADE_NORMAL_CLEAR = 1;

    public const GRADE_GOLD_CLEAR = 2;

    /** Byte width of the got_dan_flg buffer sent to the cabinet. */
    public const FLAG_BYTES = 64;

    protected $table = 'player_dan_progress';

    protected $fillable = [
        'baid',
        'game_version',
        'got_dan_flg',
        'got_dan_max',
        'disp_taikojuku_dan',
    ];

    protected $casts = [
        'got_dan_flg' => PostgresBytea::class,
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'baid', 'baid');
    }

    public static function resolve(int $baid, TaikoGameVersion $version): self
    {
        return self::query()->firstOrNew([
            'baid' => $baid,
            'game_version' => $version->value,
        ]);
    }

    /**
     * Apply a completed dan attempt. Grades only ever improve, the highest
     * cleared dan and the displayed dan are recomputed from the merged
     * bitfield. Non-normal dan ids are ignored.
     */
    public function recordDanPlay(int $danId, int $danResult): void
    {
        if ($danId < self::MIN_NORMAL_DAN || $danId > self::MAX_NORMAL_DAN) {
            return;
        }

        // Reject invalid grades outright rather than coercing them, matching
        // TaikoLocalServer which skips the save when DanResult exceeds GoldClear.
        if ($danResult < self::GRADE_NOT_CLEAR || $danResult > self::GRADE_GOLD_CLEAR) {
            return;
        }

        $grade = $this->clampGrade($danResult);
        $buffer = $this->flagBuffer();
        $packedIndex = $danId - self::MIN_NORMAL_DAN;
        $merged = max($this->getPackedGrade($buffer, $packedIndex), $grade);
        $this->setPackedGrade($buffer, $packedIndex, $merged);

        $this->got_dan_flg = $buffer;
        $this->got_dan_max = $this->computeGotDanMax($buffer);
        // Advance the displayed dan only when THIS play cleared it (the incoming
        // grade), not when an earlier session already had — otherwise a failed
        // replay of an already-cleared dan would wrongly bump the menu forward.
        $this->disp_taikojuku_dan = $this->isClear($grade)
            ? min($danId + 1, self::MAX_NORMAL_DAN)
            : $this->normalizeDisplayDan((int) ($this->disp_taikojuku_dan ?: self::MIN_NORMAL_DAN), $buffer);
    }

    /**
     * got_dan_flg padded/truncated to the wire width.
     */
    public function gotDanFlgBytes(): string
    {
        return $this->flagBuffer();
    }

    /**
     * Clear grade for every normal dan (1..25), keyed by dan id.
     * 0 = not cleared, 1 = clear, 2 = gold.
     *
     * @return array<int, int>
     */
    public function normalDanGrades(): array
    {
        $buffer = $this->flagBuffer();
        $grades = [];
        for ($dan = self::MIN_NORMAL_DAN; $dan <= self::MAX_NORMAL_DAN; $dan++) {
            $grades[$dan] = $this->getPackedGrade($buffer, $dan - self::MIN_NORMAL_DAN);
        }

        return $grades;
    }

    private function flagBuffer(): string
    {
        $raw = (string) ($this->got_dan_flg ?? '');

        return str_pad(substr($raw, 0, self::FLAG_BYTES), self::FLAG_BYTES, "\x00");
    }

    private function clampGrade(int $danResult): int
    {
        if ($danResult >= self::GRADE_GOLD_CLEAR) {
            return self::GRADE_GOLD_CLEAR;
        }

        return max(self::GRADE_NOT_CLEAR, $danResult);
    }

    private function isClear(int $grade): bool
    {
        return $grade >= self::GRADE_NORMAL_CLEAR;
    }

    /**
     * Decode the 2-bit packed wire value at a slot into a clear grade.
     */
    private function getPackedGrade(string $buffer, int $packedIndex): int
    {
        $value = 0;
        $bitOffset = $packedIndex * 2;
        for ($bit = 0; $bit < 2; $bit++) {
            $absoluteBit = $bitOffset + $bit;
            $byteIndex = $absoluteBit >> 3;
            if ($byteIndex >= strlen($buffer)) {
                break;
            }
            if ((ord($buffer[$byteIndex]) & (1 << ($absoluteBit & 7))) !== 0) {
                $value |= 1 << $bit;
            }
        }

        // Wire 0 => not clear, 1|2 => clear, 3 => gold.
        return match ($value) {
            0 => self::GRADE_NOT_CLEAR,
            3 => self::GRADE_GOLD_CLEAR,
            default => self::GRADE_NORMAL_CLEAR,
        };
    }

    private function setPackedGrade(string &$buffer, int $packedIndex, int $grade): void
    {
        $wire = match ($grade) {
            self::GRADE_NORMAL_CLEAR => 2,
            self::GRADE_GOLD_CLEAR => 3,
            default => 0,
        };

        $bitOffset = $packedIndex * 2;
        for ($bit = 0; $bit < 2; $bit++) {
            $absoluteBit = $bitOffset + $bit;
            $byteIndex = $absoluteBit >> 3;
            $byteVal = ord($buffer[$byteIndex]);
            if (($wire & (1 << $bit)) !== 0) {
                $byteVal |= 1 << ($absoluteBit & 7);
            } else {
                $byteVal &= ~(1 << ($absoluteBit & 7));
            }
            $buffer[$byteIndex] = chr($byteVal);
        }
    }

    private function computeGotDanMax(string $buffer): int
    {
        $max = 0;
        for ($dan = self::MIN_NORMAL_DAN; $dan <= self::MAX_NORMAL_DAN; $dan++) {
            if ($this->isClear($this->getPackedGrade($buffer, $dan - self::MIN_NORMAL_DAN))) {
                $max = $dan;
            }
        }

        return $max;
    }

    /**
     * Keep the menu pointed at the lowest uncleared dan unless the player has a
     * still-valid manual selection.
     */
    private function normalizeDisplayDan(int $savedDisplayDan, string $buffer): int
    {
        $savedIsNormal = $savedDisplayDan >= self::MIN_NORMAL_DAN && $savedDisplayDan <= self::MAX_NORMAL_DAN;
        if ($savedIsNormal && ! $this->isClear($this->getPackedGrade($buffer, $savedDisplayDan - self::MIN_NORMAL_DAN))) {
            return $savedDisplayDan;
        }

        for ($dan = self::MIN_NORMAL_DAN; $dan <= self::MAX_NORMAL_DAN; $dan++) {
            if (! $this->isClear($this->getPackedGrade($buffer, $dan - self::MIN_NORMAL_DAN))) {
                return $dan;
            }
        }

        return self::MAX_NORMAL_DAN;
    }
}
