<?php

use App\GameProtocol\Green\Support\FormPayloads;
use App\GameProtocol\Green\Support\ProtocolPayloads;
use App\GameProtocol\Green\Support\ScoreMapper;

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
