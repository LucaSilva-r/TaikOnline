<?php

namespace App\GameProtocol\Services;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\ItemShopCatalog;
use App\GameProtocol\Support\MessageWriter;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ScoreMapper;
use App\Models\Player;
use App\Models\PlayerBlueBattleNpcState;
use App\Models\PlayerBlueBattleState;
use App\Models\PlayerBlueBattleTokenState;
use App\Models\PlayerCosmetic;
use App\Models\PlayerGreenGhostState;
use App\Models\PlayerGreenGhostToken;
use App\Models\PlayerGreenGhostWinnings;
use App\Models\PlayerShopSeasonState;
use App\Models\PlayerTokkunStageResult;
use App\Models\PlayerTokkunState;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Carbon\CarbonImmutable;
use Google\Protobuf\Internal\Message;
use Illuminate\Support\Facades\DB;

class PlayResultService
{
    public function __construct(
        private readonly ScoreMapper $scoreMapper,
        private readonly ProtocolMessageResolver $messages,
        private readonly MessageWriter $writer,
    ) {}

    public function save(Message $data, TaikoGameVersion $version): int
    {
        $player = Player::query()->find($data->getBaid());
        if (! $player instanceof Player) {
            return 1;
        }

        // Wrap the whole persist in a transaction: the cabinet retries the save
        // when the HTTP response is not a valid protobuf, so a partial failure
        // mid-write must roll back rather than leave duplicate result rows.
        return DB::transaction(fn (): int => $this->persist($player, $data, $version));
    }

    private function persist(Player $player, Message $data, TaikoGameVersion $version): int
    {
        $gameVersion = $version->value;
        $playedAt = $this->parsePlayedAt($data->getPlayDatetime());

        $isTokkun = false;
        if (method_exists($data, 'hasAryTokkunstageInfo') && $data->hasAryTokkunstageInfo() && $data->getAryTokkunstageInfo() !== null) {
            $isTokkun = true;
        }

        if (method_exists($data, 'hasTokkunTutorialFlg') && $data->hasTokkunTutorialFlg()) {
            $tutorialFlg = $data->getTokkunTutorialFlg();
            PlayerTokkunState::query()->updateOrCreate(
                ['baid' => $player->baid, 'game_version' => $version->value],
                ['tokkun_tutorial_flg' => $tutorialFlg]
            );
        }

        // The cabinet reports when it has shown the shop / wai-wai tutorials so the
        // server can stop replaying them on the next boot. The cabinet only sends a
        // set flag once the tutorial has played, so persist it on the player.
        if (method_exists($data, 'hasItemshopTutorialFlg') && $data->hasItemshopTutorialFlg()) {
            $player->item_shop_tutorial_flg = (int) $data->getItemshopTutorialFlg();
        }

        if (method_exists($data, 'hasWaiwaiTutorialFlg') && $data->hasWaiwaiTutorialFlg()) {
            $player->waiwai_tutorial_flg = (int) $data->getWaiwaiTutorialFlg();
        }

        if ($player->isDirty(['item_shop_tutorial_flg', 'waiwai_tutorial_flg'])) {
            $player->save();
        }

        if ($isTokkun) {
            $this->saveTokkunData($player, $data, $version);

            return 1;
        }

        foreach ($data->getAryStageInfo() as $stage) {
            if (! $stage instanceof Message) {
                continue;
            }

            $rank = $this->scoreMapper->rankForScore($stage->getPlayScore());

            SongPlayResult::query()->create([
                'baid' => $player->baid,
                'game_version' => $gameVersion,
                'chassis_id' => $data->getChassisId(),
                'shop_id' => $data->getShopId(),
                'played_at' => $playedAt,
                'is_right' => $data->getIsRight(),
                'is_two_players' => $data->getIsTwoPlayers(),
                'song_no' => $stage->getSongNo(),
                'level' => $stage->getLevel(),
                'stage_mode' => $this->optionalInt($stage, 'getStageMode'),
                'play_result' => $stage->getPlayResult(),
                'score' => $stage->getPlayScore(),
                'score_rank' => $rank,
                'good_count' => $stage->getGoodCnt(),
                'ok_count' => $stage->getOkCnt(),
                'miss_count' => $stage->getNgCnt(),
                'drumroll_count' => $stage->getPoundCnt(),
                'combo_count' => $stage->getComboCnt(),
                'hit_count' => $stage->getHitCnt(),
                'music_category' => $stage->getMusicCateg(),
                'selected_folder_id' => $this->optionalInt($stage, 'getSelectedFolderId'),
                'raw_stage' => [
                    'star_level' => method_exists($stage, 'getStarLevel') ? $stage->getStarLevel() : null,
                    'support_level' => method_exists($stage, 'getSupportLevel') ? $stage->getSupportLevel() : null,
                    'is_favorite' => $stage->getIsFavorite(),
                    'is_recent' => $stage->getIsRecent(),
                ],
                'ghost_sections' => $this->extractGhostSections($stage),
            ]);

            $this->updateBest($player, $stage, $rank, $gameVersion);
        }

        $getDonmedal = method_exists($data, 'getGetDonmedal') ? (int) $data->getGetDonmedal() : 0;
        $getKatsumedal = method_exists($data, 'getGetKatsumedal') ? (int) $data->getGetKatsumedal() : 0;

        $catalog = new ItemShopCatalog($version);
        $activeSeason = $catalog->getActiveSeason();
        $totalGetDonmedal = (int) $player->total_get_donmedal;

        if ($catalog->isEnabled && $activeSeason) {
            $seasonState = PlayerShopSeasonState::query()->firstOrCreate([
                'baid' => $player->baid,
                'game_version' => $version->value,
                'season_id' => $activeSeason['season_id'],
            ]);
            $seasonState->total_get_donmedal += $getDonmedal;
            $seasonState->save();
        } else {
            $totalGetDonmedal += $getDonmedal;
        }

        $attributes = [
            'last_played_at' => $playedAt,
            'recent_song_numbers' => $this->recentSongs($player, $data),
            'total_credit_count' => (int) $player->total_credit_count + 1,
            'total_get_donmedal' => $totalGetDonmedal,
            'total_get_katsumedal' => (int) $player->total_get_katsumedal + $getKatsumedal,
        ];

        // Some dialects (e.g. White) omit the difficulty-played fields from the
        // PlayResultRequest entirely, so only persist them when present.
        if (method_exists($data, 'getDifficultyPlayedCourse')) {
            $attributes['difficulty_played_course'] = $data->getDifficultyPlayedCourse();
        }

        if (method_exists($data, 'getDifficultyPlayedStar')) {
            $attributes['difficulty_played_star'] = $data->getDifficultyPlayedStar();
        }

        $player->update($attributes);

        if (method_exists($data, 'getReleaseSongNo')) {
            $player->update([
                'unlocked_song_numbers' => $this->mergeIds($player->unlocked_song_numbers ?? [], $data->getReleaseSongNo()),
            ]);
        }

        $this->persistCosmetics($player, $data, $version);

        if ($version === TaikoGameVersion::Blue) {
            $this->saveBlueBattleData($player, $data);
        }

        if ($version === TaikoGameVersion::Green) {
            $this->saveGreenGhostData($player, $data);
        }

        return 1;
    }

    private function optionalInt(Message $message, string $getter): int
    {
        return method_exists($message, $getter) ? (int) $message->{$getter}() : 0;
    }

    /**
     * Persist the version-scoped cosmetic state a play result carries: the
     * costume/tone/title unlocks granted this play and the equipped costume.
     * Cosmetic ids map to different items per version, so this is keyed per
     * (baid, game_version). Getters are guarded for older dialects that omit
     * some of these fields.
     */
    private function persistCosmetics(Player $player, Message $data, TaikoGameVersion $version): void
    {
        $cosmetic = PlayerCosmetic::resolve($player->baid, $version);

        if (method_exists($data, 'getGetToneNo')) {
            $cosmetic->unlocked_tones = $this->mergeIds($cosmetic->unlocked_tones ?? [], $data->getGetToneNo());
        } elseif (method_exists($data, 'getToneFlg')) {
            $cosmetic->unlocked_tones = $this->mergeIds($cosmetic->unlocked_tones ?? [], $this->scoreMapper->flagBytesToIds($data->getToneFlg()));
        }

        if (method_exists($data, 'getGetTitleNo')) {
            $cosmetic->unlocked_titles = $this->mergeIds($cosmetic->unlocked_titles ?? [], $data->getGetTitleNo());
        } elseif (method_exists($data, 'getTitleFlg')) {
            $cosmetic->unlocked_titles = $this->mergeIds($cosmetic->unlocked_titles ?? [], $this->scoreMapper->flagBytesToIds($data->getTitleFlg()));
        }

        $costumes = $cosmetic->unlocked_costumes ?? [];
        for ($slot = 1; $slot <= 5; $slot++) {
            $getter = "getGetCostumeNo{$slot}";
            if (method_exists($data, $getter)) {
                $costumes[(string) $slot] = $this->mergeIds($costumes[(string) $slot] ?? [], $data->{$getter}());
            } else {
                $flgGetter = "getCostumeFlg{$slot}";
                if (method_exists($data, $flgGetter)) {
                    $costumes[(string) $slot] = $this->mergeIds($costumes[(string) $slot] ?? [], $this->scoreMapper->flagBytesToIds($data->{$flgGetter}()));
                } elseif ($slot === 1 && method_exists($data, 'getCostumeFlg')) {
                    $costumes[(string) 1] = $this->mergeIds($costumes[(string) 1] ?? [], $this->scoreMapper->flagBytesToIds($data->getCostumeFlg()));
                }
            }
        }
        $cosmetic->unlocked_costumes = $costumes;

        if (method_exists($data, 'getCurrentTitle')) {
            $cosmetic->title = (string) $data->getCurrentTitle();
        }

        $this->applyEquippedCostume($cosmetic, $data);
        $this->applyDefaultSettings($cosmetic, $data, $version);

        $cosmetic->save();
    }

    /**
     * Carry the tone and play options the player used on their last stage into
     * the cosmetic row so the cabinet pre-selects them next session. The cabinet
     * reports the tone as a bitfield (one bit = the equipped tone id) and the
     * options as a little-endian flag value.
     */
    private function applyDefaultSettings(PlayerCosmetic $cosmetic, Message $data, TaikoGameVersion $version): void
    {
        if (! method_exists($data, 'getAryStageInfo')) {
            return;
        }

        $stage = collect($data->getAryStageInfo())
            ->filter(fn (mixed $stage): bool => $stage instanceof Message)
            ->last();

        if (! $stage instanceof Message) {
            return;
        }

        if (method_exists($stage, 'getToneFlg')) {
            $toneId = $this->firstSetBit($stage->getToneFlg());
            if ($toneId !== null) {
                $cosmetic->default_tone_setting = $toneId;
            }
        }

        if (method_exists($stage, 'getOptionFlg')) {
            $cosmetic->default_option_setting = $this->decodeOptionFlg($stage->getOptionFlg(), $version);
        }
    }

    /**
     * Index of the lowest set bit in a flag bitfield, or null when empty.
     */
    private function firstSetBit(string $flag): ?int
    {
        $length = strlen($flag);
        for ($byte = 0; $byte < $length; $byte++) {
            $value = ord($flag[$byte]);
            if ($value === 0) {
                continue;
            }

            for ($bit = 0; $bit < 8; $bit++) {
                if (($value & (1 << $bit)) !== 0) {
                    return $byte * 8 + $bit;
                }
            }
        }

        return null;
    }

    /**
     * Decode the option flag bytes into an integer based on the game version's endianness.
     */
    private function decodeOptionFlg(string $bytes, TaikoGameVersion $version): int
    {
        $isLegacy = in_array($version, [
            TaikoGameVersion::Sorairo,
            TaikoGameVersion::Momoiro,
            TaikoGameVersion::Kimidori,
        ], true);

        if ($isLegacy) {
            $padded = str_pad(substr($bytes, 0, 2), 2, "\0", STR_PAD_LEFT);

            return unpack('n', $padded)[1];
        }

        $padded = str_pad(substr($bytes, 0, 4), 4, "\0");

        return unpack('V', $padded)[1];
    }

    private function applyEquippedCostume(PlayerCosmetic $cosmetic, Message $data): void
    {
        if (method_exists($data, 'getAryCurrentCostume') && $data->hasAryCurrentCostume()) {
            $costume = $data->getAryCurrentCostume();
            if ($costume instanceof Message) {
                for ($slot = 1; $slot <= 5; $slot++) {
                    $cosmetic->{"costume_{$slot}"} = (int) $costume->{"getCostume{$slot}"}();
                }
            }
        } elseif (method_exists($data, 'getCurrentCostume')) {
            $cosmetic->costume_1 = (int) $data->getCurrentCostume();
        }
    }

    /**
     * Merge incoming ids into an existing list, dropping zeros/duplicates.
     *
     * @param  array<int, int>  $existing
     * @param  iterable<int>  $incoming
     * @return array<int, int>
     */
    private function mergeIds(array $existing, iterable $incoming): array
    {
        return collect($existing)
            ->merge(collect($incoming)->map(fn (mixed $id): int => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int>  $songNumbers
     */
    public function selfBest(Player $player, int $level, TaikoGameVersion $version, iterable $songNumbers): Message
    {
        $numbers = collect($songNumbers)->map(fn (mixed $value): int => (int) $value)->filter()->values();
        $query = SongBest::query()
            ->where('baid', $player->baid)
            ->where('game_version', $version->value);

        if ($level > 0) {
            $query->where('level', $level);
        }

        if ($numbers->isNotEmpty()) {
            $query->whereIn('song_no', $numbers);
        }

        $items = $query->get()
            ->map(fn (SongBest $best): Message => $this->writer->fill(
                $this->messages->make($version, 'SelfBestResponse\\SelfBestData'),
                [
                    'setSongNo' => (int) $best->song_no,
                    'setSelfBestScore' => (int) $best->best_score,
                    'setUraBestScore' => 0,
                ],
            ))
            ->all();

        return $this->writer->fill($this->messages->make($version, 'SelfBestResponse'), [
            'setResult' => 1,
            'setLevel' => $level,
            'setArySelfbestScore' => $items,
            'setAryShinSelfbestScore' => [],
        ]);
    }

    private function updateBest(Player $player, Message $stage, int $rank, string $gameVersion): void
    {
        $best = SongBest::query()->firstOrNew([
            'baid' => $player->baid,
            'game_version' => $gameVersion,
            'song_no' => $stage->getSongNo(),
            'level' => $stage->getLevel(),
        ]);

        $dirty = ! $best->exists;

        if (! $best->exists || $stage->getPlayScore() >= (int) $best->best_score) {
            $best->fill([
                'best_score' => $stage->getPlayScore(),
                'best_score_rank' => $rank,
                'best_play_result' => $stage->getPlayResult(),
            ]);
            $dirty = true;
        }

        // Crowns improve independently of score: a later lower-scoring full combo
        // still upgrades the crown. Rank order matches the stored values
        // (0 none < 1 clear < 2 gold < 3 dondaful).
        $crown = $this->crownForPlayResult($stage->getPlayResult());
        if ($crown > (int) $best->best_crown) {
            $best->best_crown = $crown;
            $dirty = true;
        }

        if ($dirty) {
            $best->save();
        }
    }

    /**
     * Clamp the cabinet's play_result to a crown rank (1 clear, 2 gold,
     * 3 dondaful); anything else counts as no crown.
     */
    private function crownForPlayResult(int $playResult): int
    {
        return ($playResult >= 1 && $playResult <= 3) ? $playResult : 0;
    }

    private function parsePlayedAt(string $value): CarbonImmutable
    {
        if ($value === '') {
            return now()->toImmutable();
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return now()->toImmutable();
        }
    }

    /**
     * @return array<int, int>
     */
    private function recentSongs(Player $player, Message $data): array
    {
        $songs = collect($data->getAryStageInfo())
            ->filter(fn (mixed $stage): bool => $stage instanceof Message)
            ->map(fn (Message $stage): int => $stage->getSongNo());

        return $songs
            ->merge($player->recent_song_numbers ?? [])
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function extractGhostSections(Message $stage): ?array
    {
        if (! method_exists($stage, 'getGhostStagedata') || ! $stage->hasGhostStagedata() || ! $stage->getGhostStagedata() instanceof Message) {
            return null;
        }

        $sections = [];
        foreach ($stage->getGhostStagedata()->getArySectionData() as $index => $section) {
            $sections[$index] = [
                'good_cnt' => (int) $section->getGoodCnt(),
                'ok_cnt' => (int) $section->getOkCnt(),
                'ng_cnt' => (int) $section->getNgCnt(),
                'pound_cnt' => (int) $section->getPoundCnt(),
            ];
        }

        return $sections ?: null;
    }

    private function saveBlueBattleData(Player $player, Message $data): void
    {
        foreach ($data->getAryStageInfo() as $stage) {
            if ($stage instanceof Message && method_exists($stage, 'hasAryBattlestagedata') && $stage->hasAryBattlestagedata()) {
                $battleStage = $stage->getAryBattlestagedata();
                $userState = PlayerBlueBattleState::query()->firstOrCreate(['baid' => $player->baid]);
                $userState->update([
                    'last_battle_stage_id' => $battleStage->getBattleStageId(),
                    'last_boss_life' => $battleStage->getBossLife(),
                ]);

                if ($battleStage->hasNpcData()) {
                    $npc = $battleStage->getNpcData();
                    $userState->update(['last_npc_id' => $npc->getNpcId()]);

                    $npcState = PlayerBlueBattleNpcState::query()->firstOrCreate([
                        'baid' => $player->baid,
                        'npc_id' => $npc->getNpcId(),
                    ]);

                    $totalExp = (int) $npc->getTotalExp();
                    $maxDpn = max((int) $npcState->max_dpn, (int) $npc->getDpn());

                    $npcState->update([
                        'total_exp' => $totalExp,
                        'max_dpn' => $maxDpn,
                        'npc_costume_id' => $npc->getNpcCostumeId(),
                        'selected_special_id_1' => $npc->getSpecialId1(),
                        'selected_special_id_2' => $npc->getSpecialId2(),
                        'selected_special_id_3' => $npc->getSpecialId3(),
                        'bonds_level' => $npc->getBondsLv(),
                    ]);

                    $npcState->release_special_flg = $this->setBattleBits(
                        $npcState->release_special_flg,
                        array_filter([$npc->getSpecialId1(), $npc->getSpecialId2(), $npc->getSpecialId3()]),
                        16
                    );
                    $npcState->save();
                }
            }
        }

        if (method_exists($data, 'hasAryReleaseBattledata') && $data->hasAryReleaseBattledata()) {
            $releaseData = $data->getAryReleaseBattledata();
            $userState = PlayerBlueBattleState::query()->firstOrCreate(['baid' => $player->baid]);

            $infoIds = [];
            foreach ($releaseData->getReleaseInfoId() as $id) {
                $infoIds[] = (int) $id;
            }
            $userState->release_info_flg = $this->setBattleBits(
                $userState->release_info_flg,
                $infoIds,
                16
            );

            $stageIds = [];
            foreach ($releaseData->getReleaseBattleStageId() as $id) {
                $stageIds[] = (int) $id;
            }
            $userState->release_battle_stage_flg = $this->setBattleBits(
                $userState->release_battle_stage_flg,
                $stageIds,
                8
            );

            if ($releaseData->hasAssignNextStageId()) {
                $userState->assign_stage_id = $releaseData->getAssignNextStageId();
            }

            $userState->save();

            $lastNpcId = $userState->last_npc_id;
            if ($lastNpcId > 0) {
                $npcState = PlayerBlueBattleNpcState::query()->where('baid', $player->baid)->where('npc_id', $lastNpcId)->first();
                if ($npcState !== null) {
                    $costumeIds = [];
                    foreach ($releaseData->getReleaseNpcCostumeId() as $id) {
                        if ($id > 0) {
                            $costumeIds[] = (int) $id;
                        }
                    }
                    $npcState->npc_costume_flg = $this->setBattleBits(
                        $npcState->npc_costume_flg,
                        $costumeIds,
                        4
                    );

                    $specialIds = [];
                    foreach ($releaseData->getReleaseNpcSpecialId() as $id) {
                        if ($id > 0) {
                            $specialIds[] = (int) $id;
                        }
                    }
                    $npcState->release_special_flg = $this->setBattleBits(
                        $npcState->release_special_flg,
                        $specialIds,
                        16
                    );

                    $npcState->save();
                }
            }

            foreach ($releaseData->getAryBattletokendata() as $token) {
                $tokenState = PlayerBlueBattleTokenState::query()->firstOrCreate([
                    'baid' => $player->baid,
                    'token_id' => $token->getTokenId(),
                ]);
                $tokenState->update([
                    'token_value' => $token->getTokenValue(),
                ]);
            }
        }
    }

    private function setBattleBits(mixed $source, array $ids, int $byteCount): string
    {
        if (is_resource($source)) {
            $source = stream_get_contents($source);
        }
        $result = $source ?? str_repeat("\x00", $byteCount);
        if (strlen($result) < $byteCount) {
            $result = str_pad($result, $byteCount, "\x00");
        } elseif (strlen($result) > $byteCount) {
            $result = substr($result, 0, $byteCount);
        }

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id < 0 || $id >= $byteCount * 8) {
                continue;
            }

            $byteIndex = $id >> 3;
            $bitIndex = $id & 7;
            $byteVal = ord($result[$byteIndex]);
            $byteVal |= (1 << $bitIndex);
            $result[$byteIndex] = chr($byteVal);
        }

        return $result;
    }

    private function saveGreenGhostData(Player $player, Message $data): void
    {
        // 1. Handle ghost_release_data
        if (method_exists($data, 'hasGhostReleaseData') && $data->hasGhostReleaseData() && $data->getGhostReleaseData() !== null) {
            $releaseData = $data->getGhostReleaseData();
            $ghostState = PlayerGreenGhostState::query()->firstOrCreate(['baid' => $player->baid]);

            $infoIds = [];
            foreach ($releaseData->getReleaseInfoId() as $id) {
                $infoIds[] = (int) $id;
            }
            $ghostState->release_info_flag = $this->setBattleBits(
                $ghostState->release_info_flag,
                $infoIds,
                16
            );
            $ghostState->save();

            foreach ($releaseData->getAryTokendata() as $token) {
                $tokenState = PlayerGreenGhostToken::query()->firstOrCreate([
                    'baid' => $player->baid,
                    'token_id' => $token->getTokenId(),
                ]);
                $tokenState->update([
                    'token_value' => $token->getTokenValue(),
                ]);
            }
        }

        // 2. Handle ghost_update_perfdata
        if (method_exists($data, 'hasGhostUpdatePerfdata') && $data->hasGhostUpdatePerfdata() && $data->getGhostUpdatePerfdata() !== null) {
            $perfData = $data->getGhostUpdatePerfdata();
            $ghostState = PlayerGreenGhostState::query()->firstOrCreate(['baid' => $player->baid]);
            $ghostState->update([
                'input_median' => $perfData->getInputMedian(),
                'input_variance' => $perfData->getInputVariance(),
            ]);
        }

        // 3. Handle ghost_update_rank
        if (method_exists($data, 'hasGhostUpdateRank') && $data->hasGhostUpdateRank() && $data->getGhostUpdateRank() !== null) {
            $rankData = $data->getGhostUpdateRank();
            $ghostState = PlayerGreenGhostState::query()->firstOrCreate(['baid' => $player->baid]);

            $totalWinnings = 0;
            foreach ($rankData->getAryWinningsData() as $winning) {
                $totalWinnings += (int) $winning->getWinnings();

                $winningState = PlayerGreenGhostWinnings::query()->firstOrCreate([
                    'baid' => $player->baid,
                    'level_id' => $winning->getLevelId(),
                ]);
                $winningState->update([
                    'winnings' => $winning->getWinnings(),
                ]);
            }

            $ghostState->update([
                'rank_id' => $rankData->getRankId(),
                'win_point' => $rankData->getWinPoint(),
                'certified_level_id' => $rankData->getCertifiedLevelId(),
                'total_winnings' => $ghostState->total_winnings + $totalWinnings,
            ]);
        }
    }

    private function saveTokkunData(Player $player, Message $data, TaikoGameVersion $version): void
    {
        $playedAt = $this->parsePlayedAt($data->getPlayDatetime());
        $tokkunStage = $data->getAryTokkunstageInfo();

        $songNumbers = [];
        foreach ($tokkunStage->getTookunSongno() as $songNo) {
            $songNumbers[] = (int) $songNo;
        }

        PlayerTokkunStageResult::query()->create([
            'baid' => $player->baid,
            'game_version' => $version->value,
            'played_at' => $playedAt,
            'play_mode' => method_exists($data, 'getPlayMode') ? $data->getPlayMode() : 3,
            'banacoin_datetime' => method_exists($tokkunStage, 'getBanacoinDatetime') ? $tokkunStage->getBanacoinDatetime() : null,
            'tokkun_song_count' => (int) $tokkunStage->getTokkunSongCnt(),
            'tokkun_song_numbers' => $songNumbers,
            'tokkun_speedchange_count' => (int) $tokkunStage->getTokkunSpeedchangeCnt(),
            'tokkun_autoplay_count' => (int) $tokkunStage->getTokkunAutoplayCnt(),
            'tokkun_jump_count' => (int) $tokkunStage->getTokkunJumpCnt(),
        ]);
    }
}
