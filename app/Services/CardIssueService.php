<?php

namespace App\Services;

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CardIssueService
{
    public function __construct(private readonly MifareAccessCodeService $accessCodes) {}

    public function issueAnonymous(): GameCard
    {
        return $this->issue();
    }

    public function issueFor(User $user): GameCard
    {
        return $this->issue($user);
    }

    private function issue(?User $user = null): GameCard
    {
        return DB::transaction(function () use ($user): GameCard {
            $player = Player::query()->create([
                'access_token' => Str::random(32),
                'person_id' => (string) Str::uuid(),
                'user_id' => $user?->id,
            ]);

            return GameCard::query()->create([
                'access_code' => $this->unusedAccessCode(),
                'baid' => $player->baid,
            ]);
        }, attempts: 3);
    }

    public function rotate(Player $player): GameCard
    {
        return DB::transaction(function () use ($player): GameCard {
            $player = Player::query()
                ->whereKey($player->baid)
                ->lockForUpdate()
                ->firstOrFail();

            $card = GameCard::query()
                ->where('baid', $player->baid)
                ->lockForUpdate()
                ->first();

            if (! $card instanceof GameCard) {
                throw new RuntimeException('This user does not have a linked access code.');
            }

            $newAccessCode = $this->unusedAccessCode();

            GameCard::query()
                ->whereKey($card->access_code)
                ->update(['access_code' => $newAccessCode]);

            return GameCard::query()->findOrFail($newAccessCode);
        }, attempts: 3);
    }

    private function unusedAccessCode(): string
    {
        $profiles = $this->generationProfiles();

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $profile = $profiles[array_rand($profiles)];
            $accessCode = $this->accessCodes->generate(profile: $profile);

            if (! GameCard::query()->whereKey($accessCode)->exists()) {
                return $accessCode;
            }
        }

        throw new RuntimeException('Unable to allocate an unused access code.');
    }

    /**
     * @return non-empty-list<int>
     */
    private function generationProfiles(): array
    {
        $profiles = collect(config('taiko_green.nbgic_generation_profiles', [7]))
            ->map(fn (mixed $profile): int => (int) $profile)
            ->filter(fn (int $profile): bool => $profile >= 0 && $profile <= 7)
            ->unique()
            ->values()
            ->all();

        if ($profiles === []) {
            throw new RuntimeException('No NBGIC generation profiles are configured.');
        }

        return $profiles;
    }
}
