<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccessCodeBindRequest;
use App\Models\GameCard;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccessCodeController extends Controller
{
    public function update(AccessCodeBindRequest $request): RedirectResponse
    {
        $card = GameCard::query()->findOrFail($request->validated('access_code'));

        Player::query()->whereKey($card->baid)->update([
            'user_id' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access code linked.')]);

        return to_route('profile.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Player::query()
            ->where('user_id', $request->user()->id)
            ->update(['user_id' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access code unlinked.')]);

        return to_route('profile.edit');
    }
}
