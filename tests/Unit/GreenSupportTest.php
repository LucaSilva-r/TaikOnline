<?php

use App\GameProtocol\Support\FormPayloads;
use App\GameProtocol\Support\ProtocolPayloads;
use App\GameProtocol\Support\ScoreMapper;

it('decodes allnet base64 zlib forms', function (): void {
    $payload = base64_encode(gzcompress('game_id=S121&token=123'));

    expect((new FormPayloads)->decodeAllNetRequest($payload))->toBe([
        'game_id' => 'S121',
        'token' => '123',
    ]);
});

it('inflates play result payloads with optional headers', function (): void {
    $payloads = new ProtocolPayloads;
    $compressed = gzencode('abc');

    expect($payloads->inflatePlayResultData($compressed))->toBe('abc')
        ->and($payloads->inflatePlayResultData(str_repeat("\0", 32).$compressed))->toBe('abc');
});

it('maps score ranks conservatively', function (): void {
    $mapper = new ScoreMapper;

    expect($mapper->rankForScore(1000000))->toBe(8)
        ->and($mapper->rankForScore(900000))->toBe(6)
        ->and($mapper->rankForScore(1))->toBe(1);
});

it('maps song numbers to flag bytes', function (): void {
    $flags = (new ScoreMapper)->songFlagBytes([1, 8, 9], 2);

    expect(ord($flags[0]))->toBe(129)
        ->and(ord($flags[1]))->toBe(1);
});

it('unlocks every song for legacy release flags', function (string $version): void {
    // 3 distinct songs => 1 byte, all bits set (everything unlocked).
    $flags = (new ScoreMapper)->releaseSongFlagBytes($version, [1, 8, 20015]);

    expect($flags)->toBe("\xFF");
})->with(['sorairo', 'momoiro', 'kimidori', 'murasaki']);

it('keeps the song_no-indexed flag for newer release flags', function (): void {
    // Non-legacy versions still use the raw 512-byte song_no bitfield.
    $flags = (new ScoreMapper)->releaseSongFlagBytes('green', [1, 8, 9]);

    expect(strlen($flags))->toBe(512)
        ->and(ord($flags[0]))->toBe(129)
        ->and(ord($flags[1]))->toBe(1);
});

it('builds the legacy songhash table as ascending big-endian uint16s', function (): void {
    $table = (new ScoreMapper)->legacySongHashTable([20015, 1, 8]);

    expect(bin2hex($table))->toBe('000100084e2f');
});
