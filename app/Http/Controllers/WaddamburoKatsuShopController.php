<?php

namespace App\Http\Controllers;

use App\Enums\TaikoGameVersion;
use App\Events\WdbProfileChanged;
use App\Http\Controllers\Settings\CostumeController;
use App\Models\Player;
use App\Services\WaddamburoKatsuShop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Katsu Medal shop page (Donder Hiroba's かつメダル ごほうびショップ): see WaddamburoKatsuShop. */
class WaddamburoKatsuShopController extends Controller
{
    public function index(Request $request, WaddamburoKatsuShop $shop): Response
    {
        abort_unless($request->attributes->get('taikoVersionIsWaddamburo') === true, 404);
        $player = Player::query()->where('user_id', $request->user()->id)->first();
        $owned = $player === null ? [] : $shop->owned($player);

        return Inertia::render('WaddamburoKatsuShop', [
            'hasPlayer' => $player !== null,
            'medals' => $player === null ? 0 : $shop->wallet($player->baid),
            'perCredit' => WaddamburoKatsuShop::MEDALS_PER_CREDIT,
            'items' => array_map(fn (array $item): array => [
                'type' => $item[0],
                'id' => $item[1],
                'name' => $item[2],
                'price' => WaddamburoKatsuShop::PRICES[$item[0]],
                'owned' => isset($owned[$item[0].':'.$item[1]]),
                'version' => $item[3],
            ], $shop->items()),
            // Donder Hiroba's shop by version, oldest first.
            'versions' => array_values(array_map(fn (TaikoGameVersion $version): array => ['key' => $version->value, 'label' => $version->label()],
                array_filter(TaikoGameVersion::cases(), fn (TaikoGameVersion $version): bool => in_array($version->value, array_column($shop->items(), 3), true)))),
            'sheet' => CostumeController::spritesheet(TaikoGameVersion::Green),
        ]);
    }

    public function purchase(Request $request, WaddamburoKatsuShop $shop): RedirectResponse
    {
        abort_unless($request->attributes->get('taikoVersionIsWaddamburo') === true, 404);
        $player = Player::query()->where('user_id', $request->user()->id)->firstOrFail();
        $input = $request->validate(['type' => ['required', 'integer'], 'id' => ['required', 'integer']]);

        $result = $shop->buy($player, (int) $input['type'], (int) $input['id']);
        if ($result === 'ok') {
            // The games signed in as this player re-read the profile: what was bought unlocks.
            WdbProfileChanged::dispatch($player->refresh());
        }
        Inertia::flash('toast', match ($result) {
            'ok' => ['type' => 'success', 'message' => __('Bought! It is unlocked in Waddamburo.')],
            'owned' => ['type' => 'error', 'message' => __('You already have it.')],
            'short' => ['type' => 'error', 'message' => __('Not enough Katsu Medals.')],
            default => ['type' => 'error', 'message' => __('That is not sold here.')],
        });

        return back();
    }
}
