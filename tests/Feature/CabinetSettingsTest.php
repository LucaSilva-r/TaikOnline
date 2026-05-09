<?php

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

it('records heartbeat through CabinetService', function () {
    $user = User::factory()->create();
    $cabinet = app(CabinetService::class)->allocate($user);

    app(CabinetService::class)->recordHeartbeat($cabinet->serial, '10.1.2.3');

    $cabinet->refresh();
    expect($cabinet->last_ip)->toBe('10.1.2.3')
        ->and($cabinet->last_heartbeat_at)->not->toBeNull()
        ->and($cabinet->isOnline())->toBeTrue();
});
