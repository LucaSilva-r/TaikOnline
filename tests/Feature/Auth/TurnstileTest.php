<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const SiteverifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

beforeEach(function () {
    config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
    Http::preventStrayRequests();
});

test('login without a turnstile token is rejected', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('turnstile');

    $this->assertGuest();
});

test('login with a token cloudflare rejects is rejected', function () {
    Http::fake([SiteverifyUrl => Http::response(['success' => false])]);
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email, 'password' => 'password', 'cf-turnstile-response' => 'bad-token',
    ])->assertSessionHasErrors('turnstile');

    $this->assertGuest();
});

test('login with a token cloudflare accepts goes through', function () {
    Http::fake([SiteverifyUrl => Http::response(['success' => true])]);
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email, 'password' => 'password', 'cf-turnstile-response' => 'good-token',
    ])->assertSessionDoesntHaveErrors();

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn ($request) => $request['secret'] === 'secret-key' && $request['response'] === 'good-token');
});

test('cloudflare being unreachable rejects the login', function () {
    Http::fake([SiteverifyUrl => Http::failedConnection()]);
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email, 'password' => 'password', 'cf-turnstile-response' => 'good-token',
    ])->assertSessionHasErrors('turnstile');

    $this->assertGuest();
});

test('registration and password reset requests need a turnstile token', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User', 'username' => 'testuser', 'email' => 'test@example.com',
        'password' => 'password', 'password_confirmation' => 'password', 'rules' => '1',
    ])->assertSessionHasErrors('turnstile');

    $this->post(route('password.email'), ['email' => 'test@example.com'])
        ->assertSessionHasErrors('turnstile');

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});

test('the game login api is not checked', function () {
    $user = User::factory()->create();
    Player::query()->create(['user_id' => $user->id, 'mydon_name' => 'どんちゃん']);

    $this->postJson('/api/wdb/login', ['login' => $user->email, 'password' => 'password', 'device' => 'pc'])
        ->assertOk();
});

test('no secret key turns the check off', function () {
    config(['services.turnstile.secret_key' => null]);
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});
