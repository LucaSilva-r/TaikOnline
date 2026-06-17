<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Build a base64 PNG data URL of the given size for upload tests.
 */
function pngDataUrl(int $width = 256, int $height = 256): string
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    ob_start();
    imagepng($image);
    $raw = (string) ob_get_clean();
    imagedestroy($image);

    return 'data:image/png;base64,'.base64_encode($raw);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function avatarPayload(array $overrides = []): array
{
    return array_merge([
        'image' => pngDataUrl(),
        'costume' => 5,
        'color_face' => 0,
        'color_body' => 1,
        'color_limb' => 3,
        'face' => 'face_000000.png',
        'face_frame' => 0,
    ], $overrides);
}

it('shows the avatar customizer page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/green/settings/avatar')
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/DonChanAvatar')
            ->where('hasAvatar', false)
            ->has('defaults'));
});

it('stores a generated avatar and the settings it was made from', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/green/settings/avatar', avatarPayload([
            'costume' => 12,
            'color_face' => 4,
            'color_body' => 8,
            'color_limb' => 15,
            'face' => 'face_009000.png',
            'face_frame' => 7,
        ]))
        ->assertRedirect(route('avatar.edit', ['taikoVersion' => 'green']));

    Storage::disk('public')->assertExists("avatars/{$user->id}.png");

    $fresh = $user->fresh();
    expect($fresh->avatar_updated_at)->not->toBeNull()
        ->and($fresh->avatar)->toBe("/storage/avatars/{$user->id}.png?v={$fresh->avatar_updated_at->timestamp}")
        ->and($fresh->avatar_costume)->toBe(12)
        ->and($fresh->avatar_color_face)->toBe(4)
        ->and($fresh->avatar_color_body)->toBe(8)
        ->and($fresh->avatar_color_limb)->toBe(15)
        ->and($fresh->avatar_face)->toBe('face_009000.png')
        ->and($fresh->avatar_face_frame)->toBe(7);
});

it('seeds the customizer from the saved avatar settings', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)->post('/green/settings/avatar', avatarPayload([
        'costume' => 12, 'color_face' => 4, 'color_body' => 8, 'color_limb' => 15, 'face' => 'face_009000.png', 'face_frame' => 7,
    ]));

    $this->actingAs($user)->get('/green/settings/avatar')
        ->assertInertia(fn ($assert) => $assert
            ->where('defaults.costume', 12)
            ->where('defaults.colorFace', 4)
            ->where('defaults.colorBody', 8)
            ->where('defaults.colorLimb', 15)
            ->where('defaults.face', 'face_009000.png')
            ->where('defaults.faceFrame', 7));
});

it('clamps oversized avatars to the max dimension', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/green/settings/avatar', avatarPayload(['image' => pngDataUrl(1024, 1024)]))
        ->assertRedirect();

    $stored = Storage::disk('public')->get("avatars/{$user->id}.png");
    [$width, $height] = getimagesizefromstring($stored);

    expect($width)->toBeLessThanOrEqual(512)
        ->and($height)->toBeLessThanOrEqual(512);
});

it('rejects payloads that are not png data urls', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/green/settings/avatar', avatarPayload(['image' => 'data:text/html;base64,'.base64_encode('<script>')]))
        ->assertSessionHasErrors('image');

    Storage::disk('public')->assertMissing("avatars/{$user->id}.png");
    expect($user->fresh()->avatar_updated_at)->toBeNull();
});

it('rejects a png data url whose payload is not a real image', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/green/settings/avatar', avatarPayload(['image' => 'data:image/png;base64,'.base64_encode('not an image')]))
        ->assertSessionHasErrors('image');

    Storage::disk('public')->assertMissing("avatars/{$user->id}.png");
});
