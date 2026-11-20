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
use App\Services\WaddamburoShopPool;
use App\Services\WaddamburoUnlocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Waddamburo's reward shop (Green's ごほうびショップ): this calendar season's goods from Green's shop data, bought
 * with the player's Don Medals, and the day's share of the goods Green gives no way to earn (WaddamburoShopPool) in
 * the slots left. Owned goods stay owned whatever the season.
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
        $input = $request->validate(['no' => ['required', 'integer', 'min:1'], 'type' => ['nullable', 'integer'], 'id' => ['nullable', 'integer']]);
        $season = $this->season();
        $item = collect($season['items'] ?? [])->firstWhere('item_no', (int) $input['no']);
        // The pool's goods change at midnight: a client that says what it bought is not sold the next day's goods.
        $changed = $item !== null && isset($input['type'], $input['id'])
            && [$item['item_type'], $item['item_id']] !== [(int) $input['type'], (int) $input['id']];
        abort_if($item === null || $changed, 404, 'No such goods this week.');

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

    /** The game showed these earned rewards (reward_shop's animation): they leave the profile's pending_rewards. */
    public function rewardsSeen(Request $request, WaddamburoUnlocks $unlocks): JsonResponse
    {
        $baid = $this->baid($request);
        $items = $request->validate(['items' => ['required', 'array', 'max:200'], 'items.*' => ['array', 'size:2'], 'items.*.*' => ['integer']])['items'];
        $unlocks->markSeen(Player::query()->findOrFail($baid), $items);

        return response()->json(['result' => 'ok']);
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

    /** This calendar season's goods, then today's pool goods. */
    private function season(): ?array
    {
        $season = (new ItemShopCatalog(TaikoGameVersion::Green, 0))->getActiveSeason();
        if ($season !== null) {
            $season['items'] = [...$season['items'], ...app(WaddamburoShopPool::class)->today($season['items'])];
        }

        return $season;
    }

    /** @return Collection<int, string> "type:id" of every goods bought */
    private function owned(int $baid): Collection
    {
        return PlayerShopItem::query()->where('baid', $baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
            ->get(['item_type', 'item_id'])->map(fn (PlayerShopItem $item): string => $item->item_type.':'.$item->item_id);
    }
}
