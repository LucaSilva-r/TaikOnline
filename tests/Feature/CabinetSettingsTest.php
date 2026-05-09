<?php

use App\GameProtocol\Green\Proto\VsInterface\StartupAuthRequest;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthRequest\OperationData as StartupOperationData;
use App\GameProtocol\Green\Proto\VsInterface\StartupAuthResponse;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\CabinetService;

it('seeds the default cabinet on migration', function () {
    expect(Cabinet::query()->whereKey(Cabinet::DEFAULT_SERIAL)->exists())->toBeTrue();
});

it('allocates a cabinet with the 26841 prefix', function () {
    $user = User::factory()->create();

    $cabinet = app(CabinetService::class)->allocate($user, 'Test cab');

    expect($cabinet->serial)->toStartWith(Cabinet::SERIAL_PREFIX)
        ->and(strlen($cabinet->serial))->toBe(12)
        ->and($cabinet->user_id)->toBe($user->id)
        ->and($cabinet->nickname)->toBe('Test cab');
});

it('lists only the current user cabinets on the index page', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    app(CabinetService::class)->allocate($user);
    app(CabinetService::class)->allocate($other);

    $response = $this->actingAs($user)->get('/settings/cabinets');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/Cabinets')
        ->has('cabinets', 1)
    );
});

it('registers a new cabinet via the controller', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/cabinets', ['nickname' => 'Living room'])
        ->assertRedirect('/settings/cabinets');

    expect(Cabinet::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('requires a nickname when registering', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/cabinets')
        ->post('/settings/cabinets', ['nickname' => ''])
        ->assertSessionHasErrors('nickname');

    expect(Cabinet::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('revokes (deletes) a cabinet owned by the user', function () {
    $user = User::factory()->create();
    $cabinet = app(CabinetService::class)->allocate($user);

    $this->actingAs($user)
        ->delete("/settings/cabinets/{$cabinet->serial}")
        ->assertRedirect('/settings/cabinets');

    expect(Cabinet::query()->whereKey($cabinet->serial)->exists())->toBeFalse();
});

it('forbids revoking another users cabinet', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $cabinet = app(CabinetService::class)->allocate($owner);

    $this->actingAs($intruder)
        ->delete("/settings/cabinets/{$cabinet->serial}")
        ->assertForbidden();
});

it('downloads a zip with both files for the owner', function () {
    $user = User::factory()->create();
    $cabinet = app(CabinetService::class)->allocate($user);

    $response = $this->actingAs($user)->get("/settings/cabinets/{$cabinet->serial}/download");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/zip');

    $tmp = tempnam(sys_get_temp_dir(), 'cabtest_');
    file_put_contents($tmp, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($tmp))->toBeTrue();
    expect($zip->getFromName('USRDIR/dongle_serial.txt'))->toBe($cabinet->serial);

    $xml = $zip->getFromName('USRDIR/data/config/S11100-1/chassisinfo.xml');
    expect($xml)
        ->toContain('<size>2</size>')
        ->toContain($cabinet->serial)
        ->toContain(Cabinet::DEFAULT_SERIAL);

    $zip->close();
    @unlink($tmp);
});

it('captures reported config and echoes when no desired config is set', function () {
    Cabinet::query()->create(['serial' => '268415000001']);

    $request = (new StartupAuthRequest)
        ->setChassisId('268415000001')
        ->setShopId('JPN0123')
        ->setRackId('A01')
        ->setCountryId('JPN')
        ->setHddVer(1113)
        ->setUsbmemVer(1100)
        ->setUsbmemKey('USBKEY123')
        ->setAryOperationInfo([
            (new StartupOperationData)->setKeyData(1)->setValueData('1'),
            (new StartupOperationData)->setKeyData(3)->setValueData('0'),
        ]);

    $raw = test()->call(
        'POST',
        '/v01r00/chassis/startupauth.php',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/protobuf'],
        $request->serializeToString()
    );

    $raw->assertOk();

    $response = new StartupAuthResponse;
    $response->mergeFromString($raw->getContent());

    expect($response->getResult())->toBe(1);

    $ops = iterator_to_array($response->getAryOperationInfo());
    expect($ops)->toHaveCount(2)
        ->and($ops[0]->getKeyData())->toBe(1)
        ->and($ops[0]->getValueData())->toBe('1');

    $cabinet = Cabinet::query()->whereKey('268415000001')->first();
    expect($cabinet->reported_config)->toBe([
        ['key' => 1, 'value' => base64_encode('1')],
        ['key' => 3, 'value' => base64_encode('0')],
    ])->and($cabinet->last_reported_at)->not->toBeNull()
        ->and($cabinet->reported_meta)->toBe([
            'shop_id' => 'JPN0123',
            'rack_id' => 'A01',
            'country_id' => 'JPN',
            'hdd_ver' => 1113,
            'usbmem_ver' => 1100,
            'usbmem_key' => 'USBKEY123',
        ]);
});

it('returns desired config back to the cabinet when set', function () {
    Cabinet::query()->create([
        'serial' => '268415000002',
        'desired_config' => [
            ['key' => 7, 'value' => base64_encode('hello')],
        ],
    ]);

    $request = (new StartupAuthRequest)
        ->setChassisId('268415000002')
        ->setAryOperationInfo([
            (new StartupOperationData)->setKeyData(1)->setValueData('1'),
        ]);

    $raw = test()->call(
        'POST',
        '/v01r00/chassis/startupauth.php',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/protobuf'],
        $request->serializeToString()
    );

    $response = new StartupAuthResponse;
    $response->mergeFromString($raw->getContent());

    $ops = iterator_to_array($response->getAryOperationInfo());
    expect($ops)->toHaveCount(1)
        ->and($ops[0]->getKeyData())->toBe(7)
        ->and($ops[0]->getValueData())->toBe('hello');
});

it('saves desired config via the controller', function () {
    $user = User::factory()->create();
    $cabinet = app(CabinetService::class)->allocate($user, 'cab');

    $this->actingAs($user)
        ->patch("/settings/cabinets/{$cabinet->serial}/config", [
            'desired_config' => [
                ['key' => 1, 'value' => base64_encode('1')],
            ],
        ])
        ->assertRedirect("/settings/cabinets/{$cabinet->serial}");

    $cabinet->refresh();
    expect($cabinet->desired_config)->toBe([
        ['key' => 1, 'value' => base64_encode('1')],
    ]);
});

it('records heartbeat through CabinetService', function () {
    $user = User::factory()->create();
    $cabinet = app(CabinetService::class)->allocate($user);

    app(CabinetService::class)->recordHeartbeat($cabinet->serial, '10.1.2.3');

    $cabinet->refresh();
    expect($cabinet->last_ip)->toBe('10.1.2.3')
        ->and($cabinet->last_heartbeat_at)->not->toBeNull()
        ->and($cabinet->isOnline())->toBeTrue();
});
