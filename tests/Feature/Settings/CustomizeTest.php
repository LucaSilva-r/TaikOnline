<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects customization page to DonChan page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/green/settings/customize')
        ->assertRedirect('/green/settings/costumes');
});

it('shows DonChan page with access code and saved colors', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'どんちゃん',
        'color_face' => 10,
        'color_body' => 30,
        'color_limb' => 50,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'title' => '魔王に仕えし武士',
        'titleplate_id' => 2,
    ]);
    $response = $this->actingAs($user)->get('/green/settings/costumes');

    $response
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert
            ->component('settings/DonChan')
            ->where('hasAccessCode', true)
            ->where('mydonName', 'どんちゃん')
            ->where('title', '魔王に仕えし武士')
            ->where('supportsTitlePlates', true)
            ->where('titlePlateId', 2)
            ->where('officialTitleId', 4)
            ->where('officialTitles.3', [
                'id' => 4,
                'name' => '魔王に仕えし武士',
                'plate' => 2,
            ])
            ->where('colorFace', 10)
            ->where('colorBody', 30)
            ->where('colorLimb', 50));
});

it('saves custom title text and plate per game version', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $green = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'title' => 'もとの称号',
        'titleplate_id' => 0,
    ]);
    $blue = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'blue',
        'title' => '青版の称号',
        'titleplate_id' => 2,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-title', [
            'title' => ' ほんのきもち ',
            'titleplate_id' => 3,
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasNoErrors();

    expect($green->refresh()->title)->toBe('ほんのきもち')
        ->and($green->titleplate_id)->toBe(3)
        ->and($blue->refresh()->title)->toBe('青版の称号')
        ->and($blue->titleplate_id)->toBe(2);
});

it('rejects invalid custom title plate ids', function (mixed $titlePlateId): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'titleplate_id' => 2,
    ]);

    $this->actingAs($user)
        ->from('/green/settings/costumes')
        ->patch('/green/settings/donchan-title', [
            'title' => 'ほんのきもち',
            'titleplate_id' => $titlePlateId,
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasErrors('titleplate_id');

    expect($cosmetic->refresh()->titleplate_id)->toBe(2);
})->with([
    'below the known range' => -1,
    'unsupported GEN 3 plate' => 4,
    'not an integer' => 'rainbow',
]);

it('saves custom title text on versions without selectable title plates', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'white',
        'title' => 'もとの称号',
        'titleplate_id' => 0,
    ]);

    $this->actingAs($user)
        ->patch('/white/settings/donchan-title', ['title' => '白版の称号'])
        ->assertRedirect('/white/settings/costumes')
        ->assertSessionHasNoErrors();

    expect($cosmetic->refresh()->title)->toBe('白版の称号')
        ->and($cosmetic->titleplate_id)->toBe(0);
});

it('selects an official title from the current version catalog', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'title' => 'もとの称号',
        'titleplate_id' => 0,
        'unlocked_titles' => [8, 2],
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-official-title', ['title_id' => 4])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasNoErrors();

    expect($cosmetic->refresh()->title)->toBe('魔王に仕えし武士')
        ->and($cosmetic->titleplate_id)->toBe(2)
        ->and($cosmetic->unlocked_titles)->toBe([2, 4, 8]);
});

it('rejects official titles from a different version catalog', function (string $version, int $titleId): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => $version,
        'title' => 'もとの称号',
        'titleplate_id' => 0,
    ]);

    $this->actingAs($user)
        ->from("/{$version}/settings/costumes")
        ->patch("/{$version}/settings/donchan-official-title", ['title_id' => $titleId])
        ->assertRedirect("/{$version}/settings/costumes")
        ->assertSessionHasErrors('title_id');

    expect($cosmetic->refresh()->title)->toBe('もとの称号')
        ->and($cosmetic->titleplate_id)->toBe(0);
})->with([
    'title released after Momoiro' => ['momoiro', 400],
    'title absent from Blue files' => ['blue', 603],
]);

it('rejects official title updates on versions without catalogs', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/katsudon/settings/donchan-official-title', ['title_id' => 1])
        ->assertNotFound();

    expect(PlayerCosmetic::query()->where('baid', $player->baid)->exists())->toBeFalse();
});

it('clears title text only for the selected version', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $green = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'title' => 'ほんのきもち',
        'titleplate_id' => 2,
    ]);
    $blue = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'blue',
        'title' => '青版の称号',
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-title', [
            'title' => '   ',
            'titleplate_id' => 2,
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasNoErrors();

    expect($green->refresh()->title)->toBeNull()
        ->and($blue->refresh()->title)->toBe('青版の称号');
});

it('rejects title text that cannot be safely displayed', function (string $title): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);
    $cosmetic = PlayerCosmetic::query()->create([
        'baid' => $player->baid,
        'game_version' => 'green',
        'title' => 'ほんのきもち',
        'titleplate_id' => 2,
    ]);
    $this->actingAs($user)
        ->from('/green/settings/costumes')
        ->patch('/green/settings/donchan-title', [
            'title' => $title,
            'titleplate_id' => 2,
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasErrors('title');

    expect($cosmetic->refresh()->title)->toBe('ほんのきもち');
})->with([
    'more than 255 characters' => str_repeat('あ', 256),
    'line break' => "前半\n後半",
    'control character' => "前半\x00後半",
]);

it('does not create title cosmetics without a linked access code', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-title', [
            'title' => 'ほんのきもち',
            'titleplate_id' => 3,
        ])
        ->assertRedirect('/green/settings/costumes');

    $this->actingAs($user)
        ->patch('/green/settings/donchan-official-title', ['title_id' => 4])
        ->assertRedirect('/green/settings/costumes');

    expect(PlayerCosmetic::query()->where('baid', $player->baid)->exists())->toBeFalse();
});

it('saves a hiragana DonChan name for the linked player', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-name', [
            'mydon_name' => 'どんちゃん',
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasNoErrors();

    expect($player->refresh()->mydon_name)->toBe('どんちゃん');

    $this->actingAs($user)
        ->get('/blue/settings/costumes')
        ->assertOk()
        ->assertInertia(fn ($assert) => $assert->where('mydonName', 'どんちゃん'));
});

it('rejects an invalid DonChan name', function (string $name): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->from('/green/settings/costumes')
        ->patch('/green/settings/donchan-name', [
            'mydon_name' => $name,
        ])
        ->assertRedirect('/green/settings/costumes')
        ->assertSessionHasErrors('mydon_name');

    expect($player->refresh()->mydon_name)->toBe('たいこ');
})->with([
    'empty' => '',
    'more than five hiragana' => 'あいうえおか',
    'romaji' => 'donchan',
    'katakana' => 'ドンチャン',
    'kanji' => '太鼓',
    'whitespace' => 'どん ちゃん',
    'punctuation' => 'どんちゃん!',
    'emoji' => 'どん🥁',
]);

it('does not update a DonChan name without a linked access code', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/donchan-name', [
            'mydon_name' => 'どんちゃん',
        ])
        ->assertRedirect('/green/settings/costumes');

    expect($player->refresh()->mydon_name)->toBe('たいこ');
});

it('rejects DonChan name updates on unsupported game versions', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'mydon_name' => 'たいこ',
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/katsudon/settings/donchan-name', [
            'mydon_name' => 'どんちゃん',
        ])
        ->assertNotFound();

    expect($player->refresh()->mydon_name)->toBe('たいこ');
});

it('saves customization colors for player with access code', function (): void {
    $user = User::factory()->create();
    $player = Player::query()->create([
        'user_id' => $user->id,
        'color_face' => 0,
        'color_body' => 0,
        'color_limb' => 0,
    ]);
    GameCard::query()->create([
        'access_code' => '12345678901234567890',
        'baid' => $player->baid,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/customize', [
            'color_face' => 5,
            'color_body' => 25,
            'color_limb' => 45,
        ])
        ->assertRedirect('/green/settings/costumes');

    $updated = Player::query()->find($player->baid)->refresh();
    expect($updated->color_face)->toBe(5)
        ->and($updated->color_body)->toBe(25)
        ->and($updated->color_limb)->toBe(45);
});

it('does not save customization when no access code linked', function (): void {
    $user = User::factory()->create();
    Player::query()->create([
        'user_id' => $user->id,
        'color_face' => 0,
        'color_body' => 0,
        'color_limb' => 0,
    ]);

    $this->actingAs($user)
        ->patch('/green/settings/customize', [
            'color_face' => 10,
            'color_body' => 20,
            'color_limb' => 30,
        ])
        ->assertRedirect('/green/settings/costumes');

    $player = Player::query()->where('user_id', $user->id)->firstOrFail();
    expect($player->color_face)->toBe(0)
        ->and($player->color_body)->toBe(0)
        ->and($player->color_limb)->toBe(0);
});
