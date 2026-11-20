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
use App\Services\WaddamburoTitleGacha;
use App\Services\WaddamburoTitles;
use App\Services\WaddamburoUnlocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Waddamburo's "Only what I earned" builds titles from parts (updateTitleParts), not free text.
        abort_if($card !== null && $request->attributes->get('taikoVersionIsWaddamburo') === true
            && app(WaddamburoUnlocks::class)->earnedOnly($card->player), 422);

        if ($card !== null) {
            $title = $request->validated('title');
            $cosmetic = PlayerCosmetic::resolve($card->player->baid, $request->attributes->get('cosmeticVersion'));
            $cosmetic->title = is_string($title) && $title !== '' ? $title : null;

            if ($version->supportsTitlePlates()) {
                $cosmetic->titleplate_id = (int) $request->validated('titleplate_id');
            }

            $cosmetic->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Title updated.')]);

        return to_route('costumes.edit');
    }

    /** Waddamburo's title from parts (Donder Hiroba's 称号パーツ設定): part + connector + part, owned parts only. */
    public function updateTitleParts(Request $request, WaddamburoTitleGacha $gacha): RedirectResponse
    {
        abort_unless($request->attributes->get('taikoVersionIsWaddamburo') === true, 404);
        $input = $request->validate([
            'first' => ['nullable', 'string', 'max:12'],
            'connector' => ['required', 'integer', 'min:0'],
            'second' => ['nullable', 'string', 'max:12'],
            'titleplate_id' => ['required', 'integer', 'min:0', 'max:'.PlayerCosmetic::CUSTOM_TITLE_PLATE_MAX_ID],
        ]);
        $card = GameCard::query()->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))->with('player')->firstOrFail();
        $title = $gacha->compose($card->player, $input['first'] ?? null, (int) $input['connector'], $input['second'] ?? null);
        abort_if($title === null, 422);

        $cosmetic = PlayerCosmetic::resolve($card->player->baid, PlayerCosmetic::WADDAMBURO);
        $cosmetic->title = $title === '' ? null : $title;
        $cosmetic->titleplate_id = (int) $input['titleplate_id'];
        $cosmetic->save();
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
        // Waddamburo: wearing a title is not earning it (its titles unlock items); "Only what I earned" wears earned ones only.
        $waddamburo = $request->attributes->get('taikoVersionIsWaddamburo') === true;
        abort_if($waddamburo && $card !== null && app(WaddamburoUnlocks::class)->earnedOnly($card->player)
            && ! in_array($title['id'], app(WaddamburoTitles::class)->owned($card->player), true), 422);

        if ($card !== null) {
            $cosmetic = PlayerCosmetic::resolve($card->player->baid, $request->attributes->get('cosmeticVersion'));
            $cosmetic->title = $title['name'];
            $cosmetic->titleplate_id = $title['plate'];
            if (! $waddamburo) {
                $cosmetic->unlocked_titles = collect($cosmetic->unlocked_titles ?? [])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->push($title['id'])
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            }
            $cosmetic->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Official title updated.')]);

        return to_route('costumes.edit');
    }
}
