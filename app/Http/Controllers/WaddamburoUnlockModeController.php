<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerCosmetic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Waddamburo's unlock mode: "Unlock everything" (default) or "Only what I earned" (see WaddamburoUnlocks). */
class WaddamburoUnlockModeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->attributes->get('taikoVersionIsWaddamburo') === true, 404);
        $player = Player::query()->where('user_id', $request->user()->id)->firstOrFail();
        $earnedOnly = (bool) $request->validate(['earned_only' => ['required', 'boolean']])['earned_only'];

        $cosmetic = PlayerCosmetic::resolve($player->baid, PlayerCosmetic::WADDAMBURO);
        $cosmetic->wdb_earned_only = $earnedOnly;
        $cosmetic->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $earnedOnly
            ? __('Only what you earned is unlocked in Waddamburo.') : __('Everything is unlocked in Waddamburo.')]);

        return to_route('costumes.edit', ['taikoVersion' => PlayerCosmetic::WADDAMBURO]);
    }
}
