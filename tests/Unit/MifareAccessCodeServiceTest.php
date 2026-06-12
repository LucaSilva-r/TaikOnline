<?php

use App\Services\MifareAccessCodeService;
use Tests\TestCase;

uses(TestCase::class);

test('it generates and inverts mifare encodable access codes from configured records', function (): void {
    configure_nbgic_test_profiles();

    $service = app(MifareAccessCodeService::class);
    $accessCode = $service->generate(profile: 0, cardId: 0x12345678);

    expect($accessCode)->toMatch('/^300[0-9]{17}$/')
        ->and($service->isEncodable($accessCode))->toBeTrue()
        ->and($service->invert($accessCode))->toBe([
            'profile' => 0,
            'card_id' => 0x12345678,
        ])
        ->and($service->isEncodable('99999999999999999999'))->toBeFalse();
});

test('it refuses to generate cards without configured profile records', function (): void {
    config()->set('taiko_green.nbgic_profile_records', null);

    app(MifareAccessCodeService::class)->generate();
})->throws(RuntimeException::class, 'NBGIC profile records are not configured.');
