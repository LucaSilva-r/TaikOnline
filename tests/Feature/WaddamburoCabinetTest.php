<?php

use App\Models\User;
use App\Models\WdbCabinet;
use Inertia\Testing\AssertableInertia as Assert;

it('lets an issued cabinet in until it is revoked', function (): void {
    [$cabinet, $token] = WdbCabinet::issue('Left cab', null);

    $this->withToken($token)->getJson('/api/wdb/charts/'.str_repeat('a', 64).'/scores')->assertOk();
    expect($cabinet->fresh()->last_seen_at)->not->toBeNull();

    $cabinet->update(['revoked_at' => now()]);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/wdb/charts/'.str_repeat('a', 64).'/scores')->assertUnauthorized();
});

it('issues, shows once and revokes cabinets from the admin page', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/waddamburo/admin/waddamburo-cabinets', ['name' => 'Right cab'])->assertRedirect();
    $cabinet = WdbCabinet::query()->sole();
    expect($cabinet->name)->toBe('Right cab')->and($cabinet->token_hash)->toHaveLength(64);
    // The token shows on the page once, right after adding, and never again.
    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-cabinets')->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('admin/WaddamburoCabinets')->has('cabinets', 1)
            ->where('issued.name', 'Right cab')->where('issued.token', fn (string $token) => hash('sha256', $token) === $cabinet->token_hash));
    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-cabinets')
        ->assertInertia(fn (Assert $page) => $page->where('issued', null));

    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-cabinets/{$cabinet->id}/revoke")->assertRedirect();
    expect($cabinet->fresh()->revoked_at)->not->toBeNull();
});

it('keeps Zucchini and Waddamburo cabinet tokens to their own routes', function (): void {
    config(['taiko_green.zucchini_api_token_hashes' => [hash('sha256', 'zucchini-token')]]);
    [, $waddamburo] = WdbCabinet::issue('Waddamburo cab', null);
    $form = ['cabinet_id' => '44f86611', 'state' => 'entry', 'accepting' => '0'];

    $this->withToken('zucchini-token')->getJson('/api/wdb/charts/'.str_repeat('a', 64).'/scores')->assertUnauthorized();
    $this->withToken('zucchini-token')->post('/api/zucchini/pairing', $form)->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($waddamburo)->post('/api/zucchini/pairing', $form)->assertUnauthorized();
    $this->withToken($waddamburo)->post('/api/wdb/cabinet/pairing', $form)->assertOk();
});
