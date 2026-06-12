<?php

use App\GameProtocol\Proto\Blue\Taiko\BAIDRequest;
use App\GameProtocol\Proto\Blue\Taiko\BAIDResponse;
use App\Models\GameCard;
use App\Models\Player;
use App\Services\MifareAccessCodeService;

test('zucchini card issue api requires an official bearer token', function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
    configure_nbgic_test_profiles();

    $this->post('/api/zucchini/cards')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
});

test('zucchini card issue api creates a server-known encodable anonymous card', function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
    configure_nbgic_test_profiles();

    $response = $this->withHeader('Authorization', 'Bearer official-token')
        ->post('/api/zucchini/cards')
        ->assertCreated()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    $accessCode = trim($response->getContent());

    $player = Player::query()->firstOrFail();

    expect($accessCode)->toMatch('/^[0-9]{20}$/')
        ->and($accessCode)->toStartWith('308')
        ->and(GameCard::query()->whereKey($accessCode)->exists())->toBeTrue()
        ->and($player->access_token)->toHaveLength(32)
        ->and($player->person_id)->not->toBeEmpty()
        ->and(app(MifareAccessCodeService::class)->isEncodable($accessCode))->toBeTrue();

    $baid = post_protobuf('/v10r03/chassis/baidcheck.php', (new BAIDRequest)
        ->setDeviceType(0)
        ->setAccessCode($accessCode)
        ->setChipId('chip')
        ->setChassisId('268410000000')
        ->setShopId('JPN0JPN0123')
        ->setCountryId('JPN'), BAIDResponse::class);

    expect($baid->getResult())->toBe(1)
        ->and($baid->getComSvrResult())->toBe(1)
        ->and($baid->getPlayerType())->toBe(1)
        ->and($baid->getBaid())->toBe($player->baid);
});

test('zucchini card issue api respects the configured nbgic generation profiles', function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
    config()->set('taiko_green.nbgic_generation_profiles', [0]);
    configure_nbgic_test_profiles();

    $response = $this->withHeader('Authorization', 'Bearer official-token')
        ->post('/api/zucchini/cards')
        ->assertCreated();

    expect(trim($response->getContent()))->toStartWith('300');
});

test('zucchini card issue api fails closed without nbgic profile records', function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
    config()->set('taiko_green.nbgic_profile_records', null);

    $this->withHeader('Authorization', 'Bearer official-token')
        ->post('/api/zucchini/cards')
        ->assertStatus(503);
});
