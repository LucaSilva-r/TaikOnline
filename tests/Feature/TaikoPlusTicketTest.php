<?php

beforeEach(function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
    config()->set('services.taikoplus_relay.secret', 'relay-secret');
});

test('issues a relay ticket signed with the shared secret', function (): void {
    $response = $this->withHeader('Authorization', 'Bearer official-token')
        ->postJson('/api/taikoplus/ticket', ['cabinet_id' => 'ab12cd34']);

    $response->assertOk();
    [$payload, $signature] = explode('.', $response->json('ticket'));
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'relay-secret', true)), '+/', '-_'), '=');
    expect($signature)->toBe($expected);

    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    expect($claims['cab'])->toBe('ab12cd34')
        ->and($claims['exp'])->toBeGreaterThan(time());
});

test('rejects clients without the cabinet token', function (): void {
    $this->postJson('/api/taikoplus/ticket', ['cabinet_id' => 'ab12cd34'])->assertUnauthorized();
});

test('reports the relay unavailable without a secret', function (): void {
    config()->set('services.taikoplus_relay.secret', null);

    $this->withHeader('Authorization', 'Bearer official-token')
        ->postJson('/api/taikoplus/ticket', ['cabinet_id' => 'ab12cd34'])
        ->assertServiceUnavailable();
});
