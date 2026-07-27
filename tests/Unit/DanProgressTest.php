<?php

use App\Models\PlayerDanProgress;

function dan_progress(): PlayerDanProgress
{
    return new PlayerDanProgress(['baid' => 1, 'game_version' => 'green']);
}

it('packs a gold clear and advances the displayed dan', function (): void {
    $progress = dan_progress();
    $progress->recordDanPlay(1, PlayerDanProgress::GRADE_GOLD_CLEAR);

    // Dan 1 = packed slot 0; gold clear is wire value 3 in byte 0's low 2 bits.
    expect(ord($progress->gotDanFlgBytes()[0]) & 0x03)->toBe(3)
        ->and((int) $progress->got_dan_max)->toBe(1)
        ->and((int) $progress->disp_taikojuku_dan)->toBe(2);
});

it('does not advance the displayed dan when the current play fails a previously cleared dan', function (): void {
    $progress = dan_progress();
    $progress->recordDanPlay(5, PlayerDanProgress::GRADE_GOLD_CLEAR);
    expect((int) $progress->disp_taikojuku_dan)->toBe(6);

    // Replaying dan 5 and failing must not bump the displayed dan again.
    $progress->recordDanPlay(5, PlayerDanProgress::GRADE_NOT_CLEAR);

    expect((int) $progress->got_dan_max)->toBe(5)
        ->and((int) $progress->disp_taikojuku_dan)->toBe(6)
        // The stored gold clear is preserved (grades only improve).
        ->and(ord($progress->gotDanFlgBytes()[1]) & 0x03)->toBe(3);
});

it('ignores an out-of-range dan grade instead of recording a clear', function (): void {
    $progress = dan_progress();
    $progress->recordDanPlay(3, 5);

    expect((int) $progress->got_dan_max)->toBe(0)
        ->and(ord($progress->gotDanFlgBytes()[0]))->toBe(0);
});

it('ignores dan ids outside the normal 1..25 range', function (): void {
    $progress = dan_progress();
    $progress->recordDanPlay(101, PlayerDanProgress::GRADE_GOLD_CLEAR);

    expect((int) $progress->got_dan_max)->toBe(0);
});

it('does not rewind the display slot when replaying a lower cleared dan', function (): void {
    $progress = dan_progress();
    $progress->recordDanPlay(1, PlayerDanProgress::GRADE_GOLD_CLEAR);
    $progress->recordDanPlay(2, PlayerDanProgress::GRADE_GOLD_CLEAR);
    $progress->recordDanPlay(3, PlayerDanProgress::GRADE_GOLD_CLEAR);

    $progress->recordDanPlay(2, PlayerDanProgress::GRADE_GOLD_CLEAR);

    expect((int) $progress->disp_taikojuku_dan)->toBe(4)
        ->and($progress->normalizedDisplayDan())->toBe(4);
});
