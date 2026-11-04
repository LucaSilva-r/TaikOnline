<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\WdbDanCourse;
use App\Services\WaddamburoDanRank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WaddamburoDanRankController extends Controller
{
    public function update(Request $request, WaddamburoDanRank $ranks): RedirectResponse
    {
        abort_unless($request->attributes->get('taikoVersionIsWaddamburo') === true, 404);
        $player = Player::query()->where('user_id', $request->user()->id)->firstOrFail();
        $data = $request->validate(['series' => ['present', 'nullable', 'string', Rule::in(array_keys(WdbDanCourse::SERIES))]]);
        $series = $data['series'];
        if ($series !== null && $ranks->highest($player, $series) === null) {
            throw ValidationException::withMessages(['series' => __('Choose a series with a verified cleared rank.')]);
        }

        $cosmetic = PlayerCosmetic::resolve($player->baid, PlayerCosmetic::WADDAMBURO);
        $cosmetic->wdb_dan_series = $series;
        $cosmetic->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Displayed rank updated. Restart Waddamburo to refresh your nameplate.')]);

        return to_route('board.show', ['taikoVersion' => PlayerCosmetic::WADDAMBURO, 'user' => $request->user()->id]);
    }
}
