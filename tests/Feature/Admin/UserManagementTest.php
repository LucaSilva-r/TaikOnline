<?php

use App\Enums\UserRole;
use App\Models\User;

test('admins can access user management', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk();
});

test('non-admins receive 403 when accessing user management', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/users')
        ->assertForbidden();
});

test('guests are redirected to login', function () {
    $this->get('/admin/users')->assertRedirect('/login');
});

test('admins can promote and demote other users', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->patch("/admin/users/{$user->id}/role", ['role' => UserRole::Admin->value])
        ->assertRedirect();

    expect($user->refresh()->role)->toBe(UserRole::Admin);

    $this->actingAs($admin)
        ->patch("/admin/users/{$user->id}/role", ['role' => UserRole::User->value])
        ->assertRedirect();

    expect($user->refresh()->role)->toBe(UserRole::User);
});

test('admins cannot demote themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from('/admin/users')
        ->patch("/admin/users/{$admin->id}/role", ['role' => UserRole::User->value])
        ->assertSessionHasErrors('role');

    expect($admin->refresh()->role)->toBe(UserRole::Admin);
});

test('admins can delete other users', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->delete("/admin/users/{$user->id}")
        ->assertRedirect();

    expect(User::find($user->id))->toBeNull();
});

test('admins cannot delete themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from('/admin/users')
        ->delete("/admin/users/{$admin->id}")
        ->assertSessionHasErrors('user');

    expect(User::find($admin->id))->not->toBeNull();
});
