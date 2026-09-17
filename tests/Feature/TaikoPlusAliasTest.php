<?php

use App\GameProtocol\Proto\Green\Taiko\BAIDRequest;
use App\GameProtocol\Proto\Green\Taiko\BAIDResponse;
use App\GameProtocol\Proto\Green\Taiko\CrownsDataRequest;
use App\GameProtocol\Proto\Green\Taiko\CrownsDataResponse;
use App\GameProtocol\Proto\Green\Taiko\UserDataRequest;
use App\GameProtocol\Proto\Green\Taiko\UserDataResponse;
use App\Models\GameCard;
use App\Models\Player;
use App\Services\TaikoPlusAliasService;

beforeEach(function (): void {
    config()->set('taiko_green.zucchini_api_token_hashes', [hash('sha256', 'official-token')]);
    configure_nbgic_test_profiles();
});

function taikoplus_alias(string $accessCode): string
{
    return test()->withHeader('Authorization', 'Bearer official-token')
        ->postJson('/api/taikoplus/alias', ['access_code' => $accessCode])
        ->assertOk()
        ->json('alias');
}

function taikoplus_baid(string $accessCode): BAIDResponse
{
    return post_protobuf('/v11r01/chassis/baidcheck.php', (new BAIDRequest)
        ->setDeviceType(1)
        ->setAccessCode($accessCode)
        ->setChipId('chip')
        ->setChassisId('chassis')
        ->setShopId('shop')
        ->setCountryId('JPN'), BAIDResponse::class);
}

test('an alias logs the profile in under a view-only baid without credentials', function (): void {
    $player = Player::query()->create(['mydon_name' => 'DON', 'access_token' => 'secret-token', 'person_id' => 'person']);
    GameCard::query()->create(['access_code' => '12345678901234567890', 'baid' => $player->baid]);

    $alias = taikoplus_alias('12345678901234567890');
    $response = taikoplus_baid($alias);

    expect($alias)->toMatch('/^[0-9]{20}$/')->not->toBe('12345678901234567890')
        ->and($response->getResult())->toBe(1)
        ->and($response->getMydonName())->toBe('DON')
        ->and($response->getBaid() & 0xFFFFFFFF)->toBeGreaterThanOrEqual(TaikoPlusAliasService::BAID_BASE)
        ->and($response->getAccesstoken())->toBe('')
        ->and($response->getPersonid())->toBe('');

    $userData = post_protobuf('/v11r01/chassis/userdata.php', (new UserDataRequest)->setBaid($response->getBaid()), UserDataResponse::class);
    $crowns = post_protobuf('/v11r01/chassis/crownsdata.php', (new CrownsDataRequest)->setBaid($response->getBaid()), CrownsDataResponse::class);

    expect($userData->getResult())->toBe(1)
        ->and($crowns->getResult())->toBe(1)
        ->and(app(TaikoPlusAliasService::class)->readBaid($response->getBaid()))->toBe($player->baid)
        ->and(Player::query()->find($response->getBaid()))->toBeNull();
});

test('aliases need a known card and the cabinet token', function (): void {
    $this->withHeader('Authorization', 'Bearer official-token')
        ->postJson('/api/taikoplus/alias', ['access_code' => '99999999999999999999'])
        ->assertNotFound();
    $this->flushHeaders()->postJson('/api/taikoplus/alias', ['access_code' => '99999999999999999999'])->assertUnauthorized();
});
