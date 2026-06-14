<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TaikoGameVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GameSettingsUpdateRequest;
use App\Models\GameCard;
use App\Models\PlayerCosmetic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameSettingsController extends Controller
{
    /**
     * Show the user's game settings page.
     */
    public function edit(Request $request): Response
    {
        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        $version = $request->attributes->get('taikoGameVersion');
        $cosmetic = null;
        $speed = 0;
        $doron = 0;
        $abekobe = 0;
        $random = 0;

        if ($card !== null && $version instanceof TaikoGameVersion) {
            $cosmetic = PlayerCosmetic::resolve($card->player->baid, $version);
            $optionSetting = (int) $cosmetic->default_option_setting;

            $speed = $optionSetting & 7;
            $doron = ($optionSetting & 8) >> 3;
            $abekobe = ($optionSetting & 16) >> 4;
            $random = ($optionSetting & 96) >> 5;
        }

        $supportsFolderSettings = in_array($version, [
            TaikoGameVersion::Red,
            TaikoGameVersion::Yellow,
            TaikoGameVersion::Blue,
            TaikoGameVersion::Green,
        ], true);

        // Per-version feature introduction (donderhiroba):
        // - Default enso options (speed/doron/abekobe/random): Momoiro onward.
        // - Default taiko sound (tone) + in-arcade ranking difficulty: Murasaki onward.
        $supportsPlayOptions = $version?->isAtLeast(TaikoGameVersion::Momoiro) ?? false;
        $supportsTone = $version?->isAtLeast(TaikoGameVersion::Murasaki) ?? false;
        $supportsRankingDifficulty = $version?->isAtLeast(TaikoGameVersion::Murasaki) ?? false;

        return Inertia::render('settings/GameSettings', [
            'hasAccessCode' => $card !== null,
            'versionLabel' => $version?->label() ?? '',
            'prefectureId' => $card?->player->prefecture_id ?? 0,
            'isPublish' => $card?->player->is_publish ?? true,
            'dispScoreType' => $card?->player->disp_score_type ?? 0,
            'dispDanType' => $card?->player->disp_dan_type ?? 0,
            'difficultyPlayedCourse' => $card?->player->difficulty_played_course ?? 0,
            'difficultyPlayedStar' => $card?->player->difficulty_played_star ?? 0,
            'difficultyPlayedSort' => $card?->player->difficulty_played_sort ?? 0,
            'defaultToneSetting' => $cosmetic?->default_tone_setting ?? 0,
            'speed' => $speed,
            'doron' => $doron,
            'abekobe' => $abekobe,
            'random' => $random,
            'supportsFolderSettings' => $supportsFolderSettings,
            'supportsPlayOptions' => $supportsPlayOptions,
            'supportsTone' => $supportsTone,
            'supportsRankingDifficulty' => $supportsRankingDifficulty,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's game settings.
     */
    public function update(GameSettingsUpdateRequest $request): RedirectResponse
    {
        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        if ($card !== null) {
            $player = $card->player;
            $version = $request->attributes->get('taikoGameVersion');

            $supportsRankingDifficulty = $version instanceof TaikoGameVersion
                && $version->isAtLeast(TaikoGameVersion::Murasaki);

            $playerData = [
                'prefecture_id' => (int) $request->validated('prefecture_id'),
                'is_publish' => (bool) $request->validated('is_publish'),
                'disp_dan_type' => (int) $request->validated('disp_dan_type'),
                'difficulty_played_course' => (int) $request->validated('difficulty_played_course'),
                'difficulty_played_star' => (int) $request->validated('difficulty_played_star'),
                'difficulty_played_sort' => (int) $request->validated('difficulty_played_sort'),
            ];

            if ($supportsRankingDifficulty) {
                $playerData['disp_score_type'] = (int) $request->validated('disp_score_type');
            }

            $player->update($playerData);

            if ($version instanceof TaikoGameVersion) {
                $supportsPlayOptions = $version->isAtLeast(TaikoGameVersion::Momoiro);
                $supportsTone = $version->isAtLeast(TaikoGameVersion::Murasaki);

                $speed = (int) $request->validated('speed');
                $doron = (int) $request->validated('doron');
                $abekobe = (int) $request->validated('abekobe');
                $random = (int) $request->validated('random');

                $bitmask = ($speed & 7) | (($doron & 1) << 3) | (($abekobe & 1) << 4) | (($random & 3) << 5);
                $tone = (int) $request->validated('default_tone_setting');

                $allVersions = TaikoGameVersion::cases();
                $playVersions = $supportsPlayOptions
                    ? ($request->boolean('sync_play_options') ? $allVersions : [$version])
                    : [];
                $toneVersions = $supportsTone
                    ? ($request->boolean('sync_tone_settings') ? $allVersions : [$version])
                    : [];

                /** @var array<string, TaikoGameVersion> $touched */
                $touched = [];
                foreach ([...$playVersions, ...$toneVersions] as $target) {
                    $touched[$target->value] = $target;
                }

                foreach ($touched as $target) {
                    $cosmetic = PlayerCosmetic::resolve($player->baid, $target);

                    if (in_array($target, $playVersions, true)) {
                        $cosmetic->default_option_setting = $bitmask;
                    }

                    if (in_array($target, $toneVersions, true)) {
                        $cosmetic->default_tone_setting = $tone;
                    }

                    $cosmetic->save();
                }
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Game settings updated.')]);

        return to_route('game-settings.edit');
    }
}
