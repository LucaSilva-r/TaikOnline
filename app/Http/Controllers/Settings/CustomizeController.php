<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CustomizeRequest;
use App\Models\GameCard;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CustomizeController extends Controller
{
    public function edit(): RedirectResponse
    {
        return to_route('costumes.edit');
    }

    public function update(CustomizeRequest $request): RedirectResponse
    {
        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        if ($card) {
            $card->player->update([
                'color_face' => (int) $request->validated('color_face'),
                'color_body' => (int) $request->validated('color_body'),
                'color_limb' => (int) $request->validated('color_limb'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Customization updated.')]);

        return to_route('costumes.edit');
    }
}
