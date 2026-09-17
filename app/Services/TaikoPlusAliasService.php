<?php

namespace App\Services;

use App\Models\GameCard;
use App\Models\Player;
use Illuminate\Support\Facades\Cache;

/**
 * View-only stand-ins for a Taiko+ online opponent's card. A client logs the
 * opponent in with the alias access code; the profile reads back under an
 * alias BAID that no Player row owns, so scores or purchases submitted for it
 * are dropped instead of reaching the real profile.
 */
class TaikoPlusAliasService
{
    public const BAID_BASE = 0xF0000000;

    private const TTL_SECONDS = 6 * 3600;

    public function __construct(private readonly MifareAccessCodeService $accessCodes) {}

    public function issue(string $accessCode): ?string
    {
        $card = GameCard::query()->find($accessCode);
        if (! $card instanceof GameCard || $card->baid === null) {
            return null;
        }

        do {
            $aliasCode = $this->accessCodes->generate(profile: (int) (config('taiko_green.nbgic_generation_profiles', [7])[0] ?? 7));
        } while (GameCard::query()->whereKey($aliasCode)->exists() || Cache::has("taikoplus.alias.code.{$aliasCode}"));

        do {
            $aliasBaid = self::BAID_BASE | random_int(1, 0x0FFFFFFF);
        } while (Cache::has("taikoplus.alias.baid.{$aliasBaid}"));

        Cache::put("taikoplus.alias.code.{$aliasCode}", ['baid' => (int) $card->baid, 'alias_baid' => $aliasBaid], self::TTL_SECONDS);
        Cache::put("taikoplus.alias.baid.{$aliasBaid}", (int) $card->baid, self::TTL_SECONDS);

        return $aliasCode;
    }

    /** @return array{player: Player, alias_baid: int}|null */
    public function forAccessCode(string $aliasCode): ?array
    {
        $alias = Cache::get("taikoplus.alias.code.{$aliasCode}");
        $player = is_array($alias) ? Player::query()->find($alias['baid']) : null;

        return $player instanceof Player ? ['player' => $player, 'alias_baid' => $alias['alias_baid']] : null;
    }

    /** The real BAID behind an alias BAID, for read-only lookups; other BAIDs pass through. */
    public function readBaid(int $baid): int
    {
        $baid &= 0xFFFFFFFF; // protobuf uint32 reads back signed in PHP
        if ($baid < self::BAID_BASE) {
            return $baid;
        }

        return (int) Cache::get("taikoplus.alias.baid.{$baid}", 0);
    }
}
