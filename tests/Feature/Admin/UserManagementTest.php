<?php

use App\Enums\UserRole;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('admins can access user management', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/green/admin/players')
        ->assertOk();
});

test('non-admins receive 403 when accessing user management', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/green/admin/players')
        ->assertForbidden();
});

test('guests are redirected to login', function () {
    $this->get('/green/admin/players')->assertRedirect('/login');
});

test('admins can promote and demote other users', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->patch("/green/admin/players/{$user->id}/role", ['role' => UserRole::Admin->value])
        ->assertRedirect();

    expect($user->refresh()->role)->toBe(UserRole::Admin);

    $this->actingAs($admin)
        ->patch("/green/admin/players/{$user->id}/role", ['role' => UserRole::User->value])
        ->assertRedirect();

    expect($user->refresh()->role)->toBe(UserRole::User);
});

test('admins cannot demote themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from('/green/admin/players')
        ->patch("/green/admin/players/{$admin->id}/role", ['role' => UserRole::User->value])
        ->assertSessionHasErrors('role');

    expect($admin->refresh()->role)->toBe(UserRole::Admin);
});

test('admins can delete other users', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->delete("/green/admin/players/{$user->id}")
        ->assertRedirect();

    expect(User::find($user->id))->toBeNull();
});

test('admins can view the edit user page', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->get("/green/admin/players/{$user->id}/edit")
        ->assertOk();
});

test('non-admins cannot view the edit user page', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->get("/green/admin/players/{$user->id}/edit")
        ->assertForbidden();
});

test('admins can update name email and role for another user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($admin)
        ->put("/green/admin/players/{$user->id}", [
            'name' => 'Renamed User',
            'email' => 'renamed@example.com',
            'role' => UserRole::Admin->value,
        ])
        ->assertRedirect('/green/admin/players');

    $user->refresh();
    expect($user->name)->toBe('Renamed User');
    expect($user->email)->toBe('renamed@example.com');
    expect($user->role)->toBe(UserRole::Admin);
    expect($user->email_verified_at)->toBeNull();
});

test('admin update validates email uniqueness', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->from("/green/admin/players/{$user->id}/edit")
        ->put("/green/admin/players/{$user->id}", [
            'name' => $user->name,
            'email' => $other->email,
            'role' => $user->role->value,
        ])
        ->assertSessionHasErrors('email');
});

test('admins cannot demote themselves via update', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from("/green/admin/players/{$admin->id}/edit")
        ->put("/green/admin/players/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::User->value,
        ])
        ->assertSessionHasErrors('role');

    expect($admin->refresh()->role)->toBe(UserRole::Admin);
});

test('admins can update another user password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->put("/green/admin/players/{$user->id}/password", [
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('new-secret-pass', $user->refresh()->password))->toBeTrue();
});

test('admin password update requires confirmation', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->from("/green/admin/players/{$user->id}/edit")
        ->put("/green/admin/players/{$user->id}/password", [
            'password' => 'new-secret-pass',
            'password_confirmation' => 'different',
        ])
        ->assertSessionHasErrors('password');
});

test('admins can link an access code to a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $player = Player::query()->create();
    GameCard::query()->create([
        'access_code' => 'AC-LINK',
        'baid' => $player->baid,
    ]);

    $this->actingAs($admin)
        ->post("/green/admin/players/{$user->id}/access-code", ['access_code' => 'AC-LINK'])
        ->assertSessionHasNoErrors();

    expect($player->refresh()->user_id)->toBe($user->id);
});

test('admins cannot link an access code already bound to another user', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $target = User::factory()->create();
    $player = Player::query()->create(['user_id' => $owner->id]);
    GameCard::query()->create([
        'access_code' => 'AC-OWNED',
        'baid' => $player->baid,
    ]);

    $this->actingAs($admin)
        ->from("/green/admin/players/{$target->id}/edit")
        ->post("/green/admin/players/{$target->id}/access-code", ['access_code' => 'AC-OWNED'])
        ->assertSessionHasErrors('access_code');

    expect($player->refresh()->user_id)->toBe($owner->id);
});

test('admins can unlink a user access code', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => 'AC-Z',
        'baid' => $player->baid,
    ]);

    $this->actingAs($admin)
        ->delete("/green/admin/players/{$user->id}/access-code")
        ->assertSessionHasNoErrors();

    expect($player->refresh()->user_id)->toBeNull();
});

test('admins can rotate a linked access code without changing player ownership', function () {
    configure_nbgic_test_profiles();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => 'AC-OLD',
        'baid' => $player->baid,
    ]);

    $this->actingAs($admin)
        ->patch("/green/admin/players/{$user->id}/access-code")
        ->assertSessionHasNoErrors();

    $card = GameCard::query()->where('baid', $player->baid)->firstOrFail();

    expect($card->access_code)->not->toBe('AC-OLD')
        ->and($card->access_code)->toMatch('/^[0-9]{20}$/')
        ->and($player->refresh()->user_id)->toBe($user->id)
        ->and(GameCard::query()->whereKey('AC-OLD')->exists())->toBeFalse();
});

test('edit page exposes linked access code', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => 'AC-EDIT',
        'baid' => $player->baid,
    ]);

    $this->actingAs($admin)
        ->get("/green/admin/players/{$user->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('accessCode', 'AC-EDIT'));
});

test('admins cannot delete themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from('/green/admin/players')
        ->delete("/green/admin/players/{$admin->id}")
        ->assertSessionHasErrors('user');

    expect(User::find($admin->id))->not->toBeNull();
});
