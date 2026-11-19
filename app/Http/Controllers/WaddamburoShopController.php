<?php

namespace App\Http\Controllers;

use App\Enums\TaikoGameVersion;
use App\Events\WdbProfileChanged;
use App\GameProtocol\Support\ItemShopCatalog;
use App\Http\Middleware\AuthenticateWaddamburo;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\Song;
use App\Services\WaddamburoMedals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Waddamburo's reward shop (Green's ごほうびショップ): this calendar season's goods from Green's shop data, bought
 * with the player's Don Medals. Owned goods stay owned whatever the season.
 */
class WaddamburoShopController extends Controller
{
    public function show(Request $request, WaddamburoMedals $medals): JsonResponse
    {
        $baid = $this->baid($request);
        $season = $this->season();
        abort_if($season === null, 404, 'The shop is closed.');
        $owned = $this->owned($baid);
        $musicIds = Song::query()->where('version', TaikoGameVersion::Green->value)
            ->whereIn('unique_id', collect($season['items'])->where('item_type', 1)->pluck('item_id'))
            ->pluck('music_id', 'unique_id');

        return response()->json([
            'season_id' => $season['season_id'],
            'telop' => $season['telop'],
            'medals' => $medals->wallet($baid),
            'goods' => array_map(fn (array $item): array => [
                'no' => $item['item_no'],
                'type' => $item['item_type'],
                'id' => $item['item_id'],
                'price' => $item['item_price'],
                'owned' => $owned->contains($item['item_type'].':'.$item['item_id']),
                // Songs: the stock song's musicid (the client's key); null for costumes.
                'music_id' => $item['item_type'] === 1 ? $musicIds->get($item['item_id']) : null,
            ], $season['items']),
        ]);
    }

    public function purchase(Request $request, WaddamburoMedals $medals): JsonResponse
    {
        $baid = $this->baid($request);
        $no = (int) $request->validate(['no' => ['required', 'integer', 'min:1']])['no'];
        $season = $this->season();
        $item = collect($season['items'] ?? [])->firstWhere('item_no', $no);
        abort_if($item === null, 404, 'No such goods this season.');

        return DB::transaction(function () use ($baid, $season, $item, $medals): JsonResponse {
            Player::query()->lockForUpdate()->findOrFail($baid);
            if ($this->owned($baid)->contains($item['item_type'].':'.$item['item_id'])) {
                return response()->json(['result' => 'owned', 'medals' => $medals->wallet($baid)], 409);
            }
            if ($medals->wallet($baid) < $item['item_price']) {
                return response()->json(['result' => 'short', 'medals' => $medals->wallet($baid)], 409);
            }
            PlayerShopItem::query()->create([
                'baid' => $baid,
                'game_version' => PlayerCosmetic::WADDAMBURO,
                'season_id' => $season['season_id'],
                'item_type' => $item['item_type'],
                'item_id' => $item['item_id'],
                'item_no' => $item['item_no'],
                'item_price' => $item['item_price'],
            ]);
            Player::query()->whereKey($baid)->increment('total_use_donmedal', $item['item_price']);
            // The games signed in as this player re-read the profile: what was bought unlocks.
            DB::afterCommit(fn () => WdbProfileChanged::dispatch(Player::query()->findOrFail($baid)));

            return response()->json(['result' => 'ok', 'medals' => $medals->wallet($baid)]);
        });
    }

    /** The account's own card, or a cabinet's card by its baid. */
    private function baid(Request $request): int
    {
        if ($request->attributes->get(AuthenticateWaddamburo::CABINET) === true) {
            $baid = (int) $request->validate(['baid' => ['required', 'integer']])['baid'];
            abort_unless(Player::query()->whereKey($baid)->exists(), 404);

            return $baid;
        }
        $player = $request->user()->player;
        abort_unless($player instanceof Player, 403, 'Your account does not have a Banapass yet.');

        return (int) $player->baid;
    }

    private function season(): ?array
    {
        return (new ItemShopCatalog(TaikoGameVersion::Green, 0))->getActiveSeason();
    }

    /** @return Collection<int, string> "type:id" of every goods bought */
    private function owned(int $baid): Collection
    {
        return PlayerShopItem::query()->where('baid', $baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
            ->get(['item_type', 'item_id'])->map(fn (PlayerShopItem $item): string => $item->item_type.':'.$item->item_id);
    }
}
