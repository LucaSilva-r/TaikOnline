<?php

use App\Models\User;
use App\Models\WdbCabinet;
use App\Notifications\WaddamburoNotice;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->legalHistory = sys_get_temp_dir().'/legal-history-'.uniqid();
    config(['broadcasting.default' => 'log', 'legal.path' => resource_path('legal'), 'legal.history' => $this->legalHistory]);
});

afterEach(function (): void {
    File::deleteDirectory($this->legalHistory);
});

function player_behind_on_rules(): User
{
    return User::factory()->create(['rules_accepted_version' => 'older']);
}

it('shows the documents to anyone', function (string $page, string $title): void {
    $this->get("/waddamburo/{$page}")->assertOk()->assertInertia(fn (Assert $response) => $response
        ->component('Legal')->where('title', $title)->where('mustAccept', false)
        ->where('html', fn (string $html): bool => str_contains($html, "<h1>{$title}</h1>")));
})->with([['rules', 'Rules'], ['privacy', 'Privacy Policy']]);

it('renders the privacy policy\'s table', function (): void {
    $this->get('/waddamburo/privacy')->assertInertia(fn (Assert $page) => $page->where('html', fn (string $html): bool => str_contains($html, '<table>')));
});

it('sends a player behind on the rules there from any page, until they accept and return', function (): void {
    $user = player_behind_on_rules();

    $this->actingAs($user)->get('/waddamburo/songs')->assertRedirect(route('legal.rules', ['taikoVersion' => 'waddamburo']));
    $this->actingAs($user)->get('/waddamburo/settings/profile')->assertRedirect(route('legal.rules', ['taikoVersion' => 'waddamburo']));
    $this->actingAs($user)->post('/waddamburo/notifications/read')->assertRedirect(route('legal.rules', ['taikoVersion' => 'waddamburo']));
    $this->actingAs($user)->get('/waddamburo/rules')->assertOk()->assertInertia(fn (Assert $page) => $page->where('mustAccept', true));

    $this->actingAs($user)->post('/waddamburo/rules/accept')->assertSessionHasErrors('accepted');
    $this->actingAs($user)->get('/waddamburo/songs');
    $this->actingAs($user)->post('/waddamburo/rules/accept', ['accepted' => '1'])->assertRedirect('/waddamburo/songs');

    expect($user->fresh())->rules_accepted_version->toBe('test-rules')->rules_accepted_at->not->toBeNull();
    $this->actingAs($user)->get('/waddamburo/songs')->assertOk();
});

it('asks again when the version changes, and asks nobody without one', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/waddamburo/songs')->assertOk();

    config(['legal.version' => 'newer']);
    $this->actingAs($user)->get('/waddamburo/songs')->assertRedirect();

    config(['legal.version' => null]);
    $this->actingAs($user)->get('/waddamburo/songs')->assertOk();
    $this->get('/waddamburo/rules')->assertNotFound();
});

it('lets a player behind on the rules log out or delete their account', function (): void {
    $user = player_behind_on_rules();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
    expect($user->fresh())->toBeNull();

    $other = player_behind_on_rules();
    $this->actingAs($other)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('needs the rules ticked at sign-up, and records them', function (): void {
    $details = ['name' => 'New', 'username' => 'newplayer', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password'];

    $this->post(route('register.store'), $details)->assertSessionHasErrors('rules');
    $this->post(route('register.store'), [...$details, 'rules' => '1'])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'new@example.com')->sole())->rules_accepted_version->toBe('test-rules');
});

it('refuses a home player\'s uploads until they accept, telling them once', function (): void {
    $user = player_behind_on_rules();
    $token = $user->createToken('game', ['wdb'])->plainTextToken;
    $sha = str_repeat('a', 64);

    foreach ([['post', '/api/wdb/plays'], ['post', '/api/wdb/dan/plays'], ['put', "/api/wdb/charts/{$sha}"]] as [$method, $url]) {
        $this->withToken($token)->json($method, $url, [])->assertForbidden()->assertJsonPath('rules_required', true);
    }

    expect($user->notifications()->sole()->data['kind'])->toBe('rules_required')
        ->and($user->notifications()->sole()->type)->toBe(WaddamburoNotice::class);
    $this->withToken($token)->getJson('/api/wdb/me')->assertStatus(404); // not an upload: still answers

    $user->update(['rules_accepted_version' => 'test-rules']);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/wdb/plays', [])->assertUnprocessable();
});

it('asks again in the game for a new version', function (): void {
    $user = player_behind_on_rules();
    $token = $user->createToken('game', ['wdb'])->plainTextToken;
    $this->withToken($token)->postJson('/api/wdb/plays', [])->assertForbidden();
    app('auth')->forgetGuards();

    config(['legal.version' => 'newer']);
    $this->withToken($token)->postJson('/api/wdb/plays', [])->assertForbidden();

    expect($user->notifications()->count())->toBe(2);
});

it('does not hold back a cabinet\'s uploads', function (): void {
    WdbCabinet::query()->create(['name' => 'Test cab', 'token_hash' => hash('sha256', 'official-token')]);

    $this->withToken('official-token')->postJson('/api/wdb/plays', [])->assertUnprocessable();
});

it('asks nothing and shows nothing while the instance has no rules of its own', function (): void {
    config(['legal.path' => storage_path('framework/testing/no-legal-here')]);
    $user = player_behind_on_rules();

    $this->actingAs($user)->get('/waddamburo/songs')->assertOk();
    $this->get('/waddamburo/rules')->assertNotFound();
    $this->withToken($user->createToken('game', ['wdb'])->plainTextToken)->postJson('/api/wdb/plays', [])->assertUnprocessable();
});

it('shows a player behind on the rules what changed since the version they accepted', function (): void {
    $legal = sys_get_temp_dir().'/legal-'.uniqid();
    File::ensureDirectoryExists($legal);
    File::put("{$legal}/rules.md", "# Rules\n\nBe nice.\n\nNo macros.");
    File::put("{$legal}/privacy.md", "# Privacy Policy\n\nWe keep your scores.");
    config(['legal.path' => $legal, 'legal.version' => 'v1']);
    $this->get('/waddamburo/privacy')->assertInertia(fn (Assert $page) => $page->where('versions', [])->where('changes', null));

    File::put("{$legal}/privacy.md", "# Privacy Policy\n\nWe keep your scores.\n\nOnline play shows your IP address to the other player.");
    config(['legal.version' => 'v2']);
    $user = User::factory()->create(['rules_accepted_version' => 'v1']);

    $this->actingAs($user)->get('/waddamburo/privacy')->assertInertia(fn (Assert $page) => $page
        ->where('versions', ['v1'])->where('since', 'v1')
        ->where('changes.0.change', 'same')->where('changes.1.change', 'same')
        ->where('changes.2.change', 'added')
        ->where('changes.2.html', fn (string $html): bool => str_contains($html, 'IP address')));
    $this->actingAs($user)->get('/waddamburo/privacy?since=')->assertInertia(fn (Assert $page) => $page->where('changes', null));
    $this->get('/waddamburo/privacy?since=../../etc')->assertInertia(fn (Assert $page) => $page->where('changes', null));

    File::deleteDirectory($legal);
});
