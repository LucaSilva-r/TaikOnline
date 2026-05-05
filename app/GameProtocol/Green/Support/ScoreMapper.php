<?php

namespace App\GameProtocol\Green\Support;

class ScoreMapper
{
    public function rankForScore(int $score): int
    {
        return match (true) {
            $score >= 1000000 => 8,
            $score >= 950000 => 7,
            $score >= 900000 => 6,
            $score >= 800000 => 5,
            $score >= 700000 => 4,
            $score >= 600000 => 3,
            $score >= 500000 => 2,
            default => 1,
        };
    }

    public function emptyFlagBytes(int $bytes = 512): string
    {
        return str_repeat("\0", $bytes);
    }
}
