<?php

use App\Models\User;
use App\Notifications\WaddamburoNotice;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config(['broadcasting.default' => 'log']);
});

function notify_of_each_category(User $user): void
{
    $user->notify(new WaddamburoNotice('Your play was refused', 'warning', 'play_rejected'));
    $user->notify(new WaddamburoNotice('You were muted', 'warning', 'sanction'));
    $user->notify(new WaddamburoNotice('Maintenance tonight'));
}

it('sorts notifications into categories, with what is unread in each', function (string $category, string $message): void {
    $user = User::factory()->create();
    notify_of_each_category($user);

    $this->actingAs($user)->get("/waddamburo/settings/notifications?category={$category}")->assertInertia(fn (Assert $page) => $page
        ->component('settings/Notifications')->where('category', $category)
        ->has('items.data', 1)->where('items.data.0.message', $message)
        ->where('categories', fn ($tabs) => collect($tabs)->pluck('unread')->all() === [1, 1, 1]));
})->with([
    ['scores', 'Your play was refused'],
    ['moderation', 'You were muted'],
    ['system', 'Maintenance tonight'],
]);

it('shows every notification without a category and refuses an unknown one', function (): void {
    $user = User::factory()->create();
    notify_of_each_category($user);

    $this->actingAs($user)->get('/waddamburo/settings/notifications')->assertInertia(fn (Assert $page) => $page->has('items.data', 3));
    $this->actingAs($user)->get('/waddamburo/settings/notifications?category=nope')->assertSessionHasErrors('category');
});

it('marks a category read and clears it, leaving the others', function (): void {
    $user = User::factory()->create();
    notify_of_each_category($user);

    $this->actingAs($user)->post('/waddamburo/settings/notifications/read-all?category=scores')->assertRedirect();
    expect($user->unreadNotifications()->count())->toBe(2)->and($user->notifications()->count())->toBe(3);

    $this->actingAs($user)->delete('/waddamburo/settings/notifications?category=moderation')->assertRedirect();
    expect($user->notifications()->count())->toBe(2)
        ->and($user->notifications()->where('data', 'like', '%sanction%')->count())->toBe(0);

    $this->actingAs($user)->delete('/waddamburo/settings/notifications')->assertRedirect();
    expect($user->notifications()->count())->toBe(0);
});

it('deletes only the account\'s own notification', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->notify(new WaddamburoNotice('Mine'));
    $other->notify(new WaddamburoNotice('Theirs'));

    $this->actingAs($user)->delete('/waddamburo/settings/notifications/'.$other->notifications()->sole()->id)->assertNotFound();
    expect($other->notifications()->count())->toBe(1);

    $this->actingAs($user)->delete('/waddamburo/settings/notifications/'.$user->notifications()->sole()->id)->assertRedirect();
    expect($user->notifications()->count())->toBe(0);
});

it('needs a login', function (): void {
    $this->get('/waddamburo/settings/notifications')->assertRedirect();
});
