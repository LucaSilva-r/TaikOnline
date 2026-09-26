<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\TaikoTitleCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CustomizeRequest;
use App\Http\Requests\Settings\UpdateDonChanNameRequest;
use App\Http\Requests\Settings\UpdateDonChanTitleRequest;
use App\Http\Requests\Settings\UpdateOfficialDonChanTitleRequest;
use App\Models\GameCard;
use App\Models\PlayerCosmetic;
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
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion || ! $version->supportsCostumeSlots()) {
            abort(404);
        }

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

    public function updateName(UpdateDonChanNameRequest $request): RedirectResponse
    {
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion || ! $version->supportsCostumeSlots()) {
            abort(404);
        }

        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        $card?->player->update([
            'mydon_name' => $request->validated('mydon_name'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DonChan name updated.')]);

        return to_route('costumes.edit');
    }

    public function updateTitle(UpdateDonChanTitleRequest $request): RedirectResponse
    {
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion || ! $version->supportsCostumeSlots()) {
            abort(404);
        }

        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        if ($card !== null) {
            $title = $request->validated('title');
            $cosmetic = PlayerCosmetic::resolve($card->player->baid, $version);
            $cosmetic->title = is_string($title) && $title !== '' ? $title : null;

            if ($version->supportsTitlePlates()) {
                $cosmetic->titleplate_id = (int) $request->validated('titleplate_id');
            }

            $cosmetic->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Title updated.')]);

        return to_route('costumes.edit');
    }

    public function updateOfficialTitle(
        UpdateOfficialDonChanTitleRequest $request,
        TaikoTitleCatalog $catalog,
    ): RedirectResponse {
        $version = $request->attributes->get('taikoGameVersion');
        if (! $version instanceof TaikoGameVersion || ! $version->supportsCostumeSlots()) {
            abort(404);
        }

        $title = $catalog->find($version, (int) $request->validated('title_id'));
        abort_if($title === null, 422);

        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        if ($card !== null) {
            $cosmetic = PlayerCosmetic::resolve($card->player->baid, $version);
            $cosmetic->title = $title['name'];
            $cosmetic->titleplate_id = $title['plate'];
            $cosmetic->unlocked_titles = collect($cosmetic->unlocked_titles ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->push($title['id'])
                ->unique()
                ->sort()
                ->values()
                ->all();
            $cosmetic->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Official title updated.')]);

        return to_route('costumes.edit');
    }
}
