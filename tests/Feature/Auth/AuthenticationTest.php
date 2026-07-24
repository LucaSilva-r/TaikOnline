<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using their email', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('users can authenticate using their username', function () {
    $user = User::factory()->create(['username' => 'taikofan']);

    $response = $this->post(route('login.store'), [
        'email' => 'taikofan',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('users return to the play page after authenticating', function () {
    $user = User::factory()->create();
    $playUrl = route('play.create', ['taikoVersion' => 'green'], absolute: false);

    $this->get($playUrl)->assertRedirect(route('login'));

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect($playUrl);
});

test('username login is case insensitive', function () {
    $user = User::factory()->create(['username' => 'taikofan']);

    $this->post(route('login.store'), [
        'email' => 'TaikoFan',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('remember me persists a remember cookie', function () {
    $user = User::factory()->create(['remember_token' => null]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $this->assertAuthenticated();

    // Fortify issues a long-lived recaller cookie and stores its token.
    expect($user->fresh()->remember_token)->not->toBeNull();

    $cookies = collect($response->headers->getCookies());
    expect($cookies->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')))->toBeTrue();
});

test('login without remember does not set a remember cookie', function () {
    $user = User::factory()->create(['remember_token' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '',
    ]);

    $this->assertAuthenticated();
    expect($user->fresh()->remember_token)->toBeNull();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
