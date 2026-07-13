<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TaikoGameVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CostumeRequest;
use App\Models\GameCard;
use App\Models\PlayerCosmetic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class CostumeController extends Controller
{
    /**
     * The five equipped costume slots, in protobuf field order. The numeric
     * suffix maps to PlayerCosmetic::costume_{1..5} and the cabinet's slot ids.
     *
     * @var array<int, string>
     */
    private const SLOTS = [
        1 => 'kigurumi',
        2 => 'head',
        3 => 'body',
        5 => 'puchi',
    ];

    public function edit(Request $request): Response
    {
        $version = $request->attributes->get('taikoGameVersion');
        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        $supported = $version instanceof TaikoGameVersion && $version->supportsCostumeSlots();

        $presets = array_fill(0, PlayerCosmetic::PRESET_COUNT, array_fill_keys(PlayerCosmetic::PRESET_KEYS, 0));
        $activePreset = 0;
        $title = '';

        if ($card !== null && $supported && $version instanceof TaikoGameVersion) {
            $cosmetic = PlayerCosmetic::resolve($card->player->baid, $version);
            $presets = $cosmetic->normalizedPresets();
            $activePreset = min((int) $cosmetic->active_costume_preset, PlayerCosmetic::PRESET_COUNT - 1);
            $title = $cosmetic->title ?? '';
        }

        return Inertia::render('settings/DonChan', [
            'supported' => $supported,
            'hasAccessCode' => $card !== null,
            'versionLabel' => $version?->label() ?? '',
            'sheet' => $this->spritesheet($version),
            'presets' => $presets,
            'activePreset' => $activePreset,
            'mydonName' => $card?->player->mydon_name ?? '',
            'title' => $title,
            'colorFace' => $card?->player->color_face ?? 0,
            'colorBody' => $card?->player->color_body ?? 0,
            'colorLimb' => $card?->player->color_limb ?? 0,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(CostumeRequest $request): RedirectResponse
    {
        $version = $request->attributes->get('taikoGameVersion');
        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        if (! $version instanceof TaikoGameVersion || ! $version->supportsCostumeSlots()) {
            abort(404);
        }

        if ($card !== null) {
            $cosmetic = PlayerCosmetic::resolve($card->player->baid, $version);

            /** @var array<int, array<string, mixed>> $rawPresets */
            $rawPresets = $request->validated('presets');
            $presets = [];
            foreach ($rawPresets as $set) {
                $presets[] = array_map(
                    fn (string $key): int => (int) ($set[$key] ?? 0),
                    array_combine(PlayerCosmetic::PRESET_KEYS, PlayerCosmetic::PRESET_KEYS)
                );
            }

            $active = (int) $request->validated('active_preset');
            $cosmetic->costume_presets = $presets;
            $cosmetic->active_costume_preset = $active;

            // Mirror the active preset into the equipped costume_1..5 columns the
            // BAID response sends as the current outfit.
            foreach ($presets[$active] as $key => $value) {
                $cosmetic->{$key} = $value;
            }

            $cosmetic->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Costume updated.')]);

        return to_route('costumes.edit');
    }

    /**
     * Load this version's prebuilt spritesheet coord map (see
     * scripts/build_costume_spritesheet.py). One image + CSS background-position
     * replaces hundreds of individual icon requests.
     *
     * @return array{url: string, cell: int, width: int, height: int, slots: array<string, array<int, array{id: int, x: int, y: int}>>}|null
     */
    private function spritesheet(?TaikoGameVersion $version): ?array
    {
        if (! $version instanceof TaikoGameVersion) {
            return null;
        }

        $path = public_path("costumes/{$version->value}/sheet.json");
        if (! File::exists($path)) {
            return null;
        }

        /** @var array{cell: int, sheet: array{0: int, 1: int}, slots: array<string, array<int, array{id: int, x: int, y: int}>>} $data */
        $data = json_decode(File::get($path), true);

        return [
            'url' => "/costumes/{$version->value}/sheet.png",
            'cell' => $data['cell'],
            'width' => $data['sheet'][0],
            'height' => $data['sheet'][1],
            'slots' => $data['slots'],
        ];
    }
}
