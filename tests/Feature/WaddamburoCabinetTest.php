<?php

use App\Enums\SanctionType;
use App\Models\Player;
use App\Models\Sanction;
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
    $admin = User::factory()->admin()->create();

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

it('makes players accept the cabinet terms before issuing their own token', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/waddamburo/settings/cabinets')
        ->assertInertia(fn (Assert $page) => $page->component('settings/Cabinets')->where('acceptedTerms', false));
    $this->actingAs($user)->post('/waddamburo/settings/cabinets', ['name' => 'My cab'])->assertForbidden();
    $this->actingAs($user)->post('/waddamburo/settings/cabinets/terms')->assertSessionHasErrors('accept');

    $this->actingAs($user)->post('/waddamburo/settings/cabinets/terms', ['accept' => '1'])->assertRedirect();
    $this->actingAs($user)->post('/waddamburo/settings/cabinets', ['name' => 'My cab'])->assertRedirect();

    expect(WdbCabinet::query()->sole())->user_id->toBe($user->id)->name->toBe('My cab');
});

it('lets players revoke only their own tokens', function (): void {
    $user = User::factory()->create();
    [$mine] = WdbCabinet::issue('Mine', $user->id, $user->id);
    [$theirs] = WdbCabinet::issue('Theirs', null);

    $this->actingAs($user)->patch("/waddamburo/settings/cabinets/{$theirs->id}/revoke")->assertNotFound();
    $this->actingAs($user)->patch("/waddamburo/settings/cabinets/{$mine->id}/revoke")->assertRedirect();

    expect($mine->fresh()->revoked_at)->not->toBeNull()->and($theirs->fresh()->revoked_at)->toBeNull();
});

it('turns a banned owner\'s tokens off and stops them issuing new ones', function (): void {
    $user = User::factory()->create(['cabinet_terms_accepted_at' => now()]);
    Player::query()->create(['user_id' => $user->id]);
    [, $token] = WdbCabinet::issue('Mine', $user->id, $user->id);
    $ban = Sanction::query()->create(['baid' => $user->player->baid, 'type' => SanctionType::Ban, 'reason' => 'bootleg']);

    expect(WdbCabinet::forToken($token))->toBeNull();
    $this->actingAs($user)->post('/waddamburo/settings/cabinets', ['name' => 'Another'])->assertForbidden();

    $ban->update(['revoked_at' => now()]);
    expect(WdbCabinet::forToken($token))->not->toBeNull();
});

it('lets admins disable and enable a token and see its owner', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    [$cabinet, $token] = WdbCabinet::issue('Their cab', $owner->id, $owner->id);

    $this->actingAs($admin)->get('/waddamburo/admin/waddamburo-cabinets')
        ->assertInertia(fn (Assert $page) => $page->where('cabinets.0.owner.id', $owner->id));

    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-cabinets/{$cabinet->id}/disable")->assertRedirect();
    expect(WdbCabinet::forToken($token))->toBeNull();

    $this->actingAs($admin)->patch("/waddamburo/admin/waddamburo-cabinets/{$cabinet->id}/enable")->assertRedirect();
    expect(WdbCabinet::forToken($token))->not->toBeNull();
});
