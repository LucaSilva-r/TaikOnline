<?php

use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerRankSnapshot;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Models\Token;
use App\Models\User;

test('admins can delete a player and all associated data', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();

    $player = Player::query()->create(['user_id' => $owner->id]);
    GameCard::query()->create([
        'access_code' => 'AC-DELETE',
        'baid' => $player->baid,
    ]);
    SongPlayResult::query()->create([
        'baid' => $player->baid,
        'song_no' => 1,
        'level' => 3,
    ]);
    SongBest::query()->create([
        'baid' => $player->baid,
        'song_no' => 1,
        'level' => 3,
        'best_score' => 100000,
    ]);
    Token::query()->create([
        'baid' => $player->baid,
        'token_id' => 1,
        'count' => 5,
    ]);
    PlayerRankSnapshot::query()->create([
        'user_id' => $owner->id,
        'game_version' => 'green',
        'rank' => 1,
        'snapshot_date' => now()->toDateString(),
    ]);

    $this->actingAs($admin)
        ->delete("/green/admin/baids/{$player->baid}")
        ->assertRedirect('/green/admin/baids');

    expect(Player::query()->whereKey($player->baid)->exists())->toBeFalse()
        ->and(GameCard::query()->whereKey('AC-DELETE')->exists())->toBeFalse()
        ->and(SongPlayResult::query()->where('baid', $player->baid)->exists())->toBeFalse()
        ->and(SongBest::query()->where('baid', $player->baid)->exists())->toBeFalse()
        ->and(Token::query()->where('baid', $player->baid)->exists())->toBeFalse()
        ->and(PlayerRankSnapshot::query()->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('non-admins cannot delete players', function () {
    $user = User::factory()->create();
    $player = Player::query()->create();

    $this->actingAs($user)
        ->delete("/green/admin/baids/{$player->baid}")
        ->assertForbidden();

    expect(Player::query()->whereKey($player->baid)->exists())->toBeTrue();
});

test('admins can replace a BAID access code and keep every score', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();

    $player = Player::query()->create(['user_id' => $owner->id]);
    GameCard::query()->create(['access_code' => 'OLD-CARD', 'baid' => $player->baid]);
    $best = SongBest::query()->create([
        'baid' => $player->baid,
        'song_no' => 1,
        'level' => 3,
        'best_score' => 950000,
    ]);

    $this->actingAs($admin)
        ->from("/green/admin/baids/{$player->baid}")
        ->patch("/green/admin/baids/{$player->baid}/access-code", ['access_code' => 'NEW-BANAPASS'])
        ->assertRedirect("/green/admin/baids/{$player->baid}");

    expect(GameCard::query()->whereKey('OLD-CARD')->exists())->toBeFalse()
        ->and((int) GameCard::query()->findOrFail('NEW-BANAPASS')->baid)->toBe((int) $player->baid)
        ->and(SongBest::query()->whereKey($best->id)->value('baid'))->toEqual($player->baid)
        ->and((int) $player->fresh()->user_id)->toBe($owner->id);
});

test('a BAID cannot take over an access code registered to another BAID', function () {
    $admin = User::factory()->admin()->create();

    $player = Player::query()->create();
    $other = Player::query()->create();
    GameCard::query()->create(['access_code' => 'MINE', 'baid' => $player->baid]);
    GameCard::query()->create(['access_code' => 'TAKEN', 'baid' => $other->baid]);

    $this->actingAs($admin)
        ->from("/green/admin/baids/{$player->baid}")
        ->patch("/green/admin/baids/{$player->baid}/access-code", ['access_code' => 'TAKEN'])
        ->assertSessionHasErrors('access_code');

    expect((int) GameCard::query()->findOrFail('MINE')->baid)->toBe((int) $player->baid)
        ->and((int) GameCard::query()->findOrFail('TAKEN')->baid)->toBe((int) $other->baid);
});

test('admins can unlink a BAID so it becomes anonymous but keeps its data', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();

    $player = Player::query()->create(['user_id' => $owner->id]);
    GameCard::query()->create(['access_code' => 'DROP-ME', 'baid' => $player->baid]);
    $best = SongBest::query()->create([
        'baid' => $player->baid,
        'song_no' => 4,
        'level' => 2,
        'best_score' => 800000,
    ]);

    $this->actingAs($admin)
        ->from("/green/admin/baids/{$player->baid}")
        ->delete("/green/admin/baids/{$player->baid}/access-code")
        ->assertRedirect("/green/admin/baids/{$player->baid}");

    expect(GameCard::query()->whereKey('DROP-ME')->exists())->toBeFalse()
        ->and(Player::query()->whereKey($player->baid)->exists())->toBeTrue()
        ->and($player->fresh()->user_id)->toBeNull()
        ->and(SongBest::query()->whereKey($best->id)->exists())->toBeTrue();
});

test('non-admins cannot replace or unlink an access code', function () {
    $user = User::factory()->create();
    $player = Player::query()->create();
    GameCard::query()->create(['access_code' => 'SAFE', 'baid' => $player->baid]);

    $this->actingAs($user)
        ->patch("/green/admin/baids/{$player->baid}/access-code", ['access_code' => 'HIJACK'])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete("/green/admin/baids/{$player->baid}/access-code")
        ->assertForbidden();

    expect(GameCard::query()->whereKey('SAFE')->exists())->toBeTrue();
});

test('admins can delete a single play of a BAID', function () {
    $admin = User::factory()->admin()->create();
    $player = Player::query()->create();

    $result = SongPlayResult::query()->create([
        'baid' => $player->baid,
        'song_no' => 7,
        'level' => 2,
    ]);

    $this->actingAs($admin)
        ->from("/green/admin/baids/{$player->baid}")
        ->delete("/green/admin/baids/{$player->baid}/plays/{$result->id}")
        ->assertRedirect("/green/admin/baids/{$player->baid}");

    expect(SongPlayResult::query()->whereKey($result->id)->exists())->toBeFalse()
        ->and(Player::query()->whereKey($player->baid)->exists())->toBeTrue();
});

test('a play cannot be deleted through a BAID it does not belong to', function () {
    $admin = User::factory()->admin()->create();
    $owner = Player::query()->create();
    $other = Player::query()->create();

    $result = SongPlayResult::query()->create([
        'baid' => $owner->baid,
        'song_no' => 7,
        'level' => 2,
    ]);

    $this->actingAs($admin)
        ->delete("/green/admin/baids/{$other->baid}/plays/{$result->id}")
        ->assertNotFound();

    expect(SongPlayResult::query()->whereKey($result->id)->exists())->toBeTrue();
});

test('admins can delete a single best score of a BAID', function () {
    $admin = User::factory()->admin()->create();
    $player = Player::query()->create();

    $best = SongBest::query()->create([
        'baid' => $player->baid,
        'song_no' => 7,
        'level' => 2,
        'best_score' => 500000,
    ]);

    $this->actingAs($admin)
        ->from("/green/admin/baids/{$player->baid}")
        ->delete("/green/admin/baids/{$player->baid}/bests/{$best->id}")
        ->assertRedirect("/green/admin/baids/{$player->baid}");

    expect(SongBest::query()->whereKey($best->id)->exists())->toBeFalse();
});
