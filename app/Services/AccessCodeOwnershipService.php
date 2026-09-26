<?php

namespace App\Services;

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessCodeOwnershipService
{
    public function claim(User $user, string $accessCode): Player
    {
        return DB::transaction(function () use ($user, $accessCode): Player {
            $card = GameCard::query()
                ->whereKey($accessCode)
                ->lockForUpdate()
                ->first();

            if (! $card instanceof GameCard) {
                $newPlayer = Player::query()->create([
                    'access_token' => Str::random(32),
                    'person_id' => (string) Str::uuid(),
                ]);

                $card = GameCard::query()->create([
                    'access_code' => $accessCode,
                    'baid' => $newPlayer->baid,
                ]);
            }

            $player = Player::query()
                ->whereKey($card->baid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($player->user_id !== null && $player->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'access_code' => __('This access code is already linked to another account.'),
                ]);
            }

            $existingPlayer = Player::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingPlayer instanceof Player && $existingPlayer->baid !== $player->baid) {
                throw ValidationException::withMessages([
                    'access_code' => __('Your account is already linked to an access code. Unbind it first.'),
                ]);
            }

            if ($player->user_id !== $user->id) {
                $player->update(['user_id' => $user->id]);
            }

            return $player->refresh();
        }, attempts: 3);
    }

    public function unclaim(User $user): void
    {
        DB::transaction(function () use ($user): void {
            Player::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get()
                ->each
                ->update(['user_id' => null]);
        }, attempts: 3);
    }
}
