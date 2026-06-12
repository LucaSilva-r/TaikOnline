<?php

use App\Enums\UserRole;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('username is stored lowercase and must be unique', function () {
    User::factory()->create(['username' => 'taken']);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'username' => 'TAKEN',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('username');

    $this->post(route('register.store'), [
        'name' => 'Mixed Case',
        'username' => 'MixedCase',
        'email' => 'mixed@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::firstWhere('email', 'mixed@example.com')->username)->toBe('mixedcase');
});

test('first registered user becomes admin', function () {
    expect(User::count())->toBe(0);

    $this->post(route('register.store'), [
        'name' => 'First User',
        'username' => 'firstuser',
        'email' => 'first@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::firstWhere('email', 'first@example.com')->role)->toBe(UserRole::Admin);
});

test('subsequent registered users are regular users', function () {
    User::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Second User',
        'username' => 'seconduser',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::firstWhere('email', 'second@example.com')->role)->toBe(UserRole::User);
});

test('new users can claim an issued access code during registration', function () {
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => 'AC-REGISTER',
        'baid' => $player->baid,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Card User',
        'username' => 'carduser',
        'email' => 'card@example.com',
        'access_code' => 'AC-REGISTER',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('home', absolute: false));

    $user = User::firstWhere('email', 'card@example.com');

    expect($player->refresh()->user_id)->toBe($user->id);
});

test('registration rejects access codes that were not issued by the server', function () {
    $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Unknown Card User',
        'username' => 'unknowncard',
        'email' => 'unknown-card@example.com',
        'access_code' => 'NO-SUCH-CODE',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('access_code');

    expect(User::where('email', 'unknown-card@example.com')->exists())->toBeFalse();
});
