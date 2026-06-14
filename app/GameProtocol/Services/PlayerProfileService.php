<?php

namespace App\GameProtocol\Services;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\ItemShopCatalog;
use App\GameProtocol\Support\MessageWriter;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ScoreMapper;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\PlayerShopSeasonState;
use App\Models\PlayerTokkunState;
use App\Models\Song;
use Google\Protobuf\Internal\Message;

class PlayerProfileService
{
    /**
     * Fixed cabinet bitset sizes (bytes) for cosmetic unlock flags.
     */
    private const TONE_FLAG_BYTES = 16;

    private const TITLE_FLAG_BYTES = 128;

    private const COSTUME_FLAG_BYTES = 32;

    /**
     * "No dan data" sentinel for disp_taikojuku_dan_. The Green client reads this
     * field at a fixed offset without a proto2 presence check, and any value
     * outside the valid 1..25 dan range (including 0 / wire-absent) underflows
     * Taikojuku_GetDanSlotSongRange and crashes the client. 1 is the value the
     * client itself initialises as its safe "absent" slot. Until real Taiko Juku
     * dan progress is tracked, always send this instead of 0.
     */
    private const SAFE_DISP_TAIKOJUKU_DAN = 1;

    public function __construct(
        private readonly ScoreMapper $scoreMapper,
        private readonly ProtocolMessageResolver $messages,
        private readonly MessageWriter $writer,
    ) {}

    private function getShopDetails(Player $player, TaikoGameVersion $version): array
    {
        $catalog = new ItemShopCatalog($version);
        $activeSeason = $catalog->getActiveSeason();

        $totalGet = 0;
        $totalUse = 0;
        $unlockedSongIds = [];
        $unlockedToneIds = [];
        $unlockedCostumeIdsBySlot = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];

        if ($catalog->isEnabled && $activeSeason) {
            $seasonState = PlayerShopSeasonState::query()->firstOrCreate([
                'baid' => $player->baid,
                'game_version' => $version->value,
                'season_id' => $activeSeason['season_id'],
            ]);
            $totalGet = $seasonState->total_get_donmedal;
            $totalUse = $seasonState->total_use_donmedal;

            $items = PlayerShopItem::query()
                ->where('baid', $player->baid)
                ->where('game_version', $version->value)
                ->where('season_id', $activeSeason['season_id'])
                ->get();

            foreach ($items as $item) {
                if ($item->item_type === 1) {
                    $unlockedSongIds[] = $item->item_id;
                } elseif ($item->item_type === 2) {
                    $unlockedToneIds[] = $item->item_id;
                } elseif ($item->item_type === 3) {
                    $unlockedCostumeIdsBySlot[1][] = $item->item_id; // kigurumi -> slot 1
                } elseif ($item->item_type === 5) {
                    $unlockedCostumeIdsBySlot[2][] = $item->item_id; // head -> slot 2
                } elseif ($item->item_type === 4) {
                    $unlockedCostumeIdsBySlot[3][] = $item->item_id; // body -> slot 3
                } elseif ($item->item_type === 6) {
                    $unlockedCostumeIdsBySlot[4][] = $item->item_id; // face -> slot 4
                } elseif ($item->item_type === 7) {
                    $unlockedCostumeIdsBySlot[5][] = $item->item_id; // puchi -> slot 5
                }
            }
        }

        return [
            'activeSeason' => $activeSeason,
            'totalGet' => $totalGet,
            'totalUse' => $totalUse,
            'unlockedSongIds' => $unlockedSongIds,
            'unlockedToneIds' => $unlockedToneIds,
            'unlockedCostumeIdsBySlot' => $unlockedCostumeIdsBySlot,
        ];
    }

    /**
     * Render one costume slot's unlocked ids into its flag bitset. Costumes are
     * stored as a {slot => [ids]} map in the version-scoped cosmetic row.
     */
    private function costumeFlag(PlayerCosmetic $cosmetic, int $slot, ?array $activeSeason = null, array $unlockedCostumeIdsBySlot = []): string
    {
        $ids = ($cosmetic->unlocked_costumes ?? [])[(string) $slot] ?? [];

        if ($activeSeason) {
            $itemType = match ($slot) {
                1 => 3, // kigurumi
                2 => 5, // head
                3 => 4, // body
                4 => 6, // face
                5 => 7, // puchi
                default => null,
            };

            if ($itemType !== null) {
                $ids = array_filter($ids, function (int $itemId) use ($activeSeason, $itemType, $unlockedCostumeIdsBySlot, $slot): bool {
                    $isShopItem = false;
                    foreach ($activeSeason['items'] as $item) {
                        if ($item['item_type'] === $itemType && $item['item_id'] === $itemId) {
                            $isShopItem = true;
                            break;
                        }
                    }
                    if ($isShopItem) {
                        $allowedIds = $unlockedCostumeIdsBySlot[$slot] ?? [];

                        return in_array($itemId, $allowedIds, true);
                    }

                    return true;
                });
            }
        }

        return $this->scoreMapper->idFlagBytes($ids, self::COSTUME_FLAG_BYTES);
    }

    /**
     * Build the equipped-costume message from the version-scoped cosmetic row.
     * Older dialects have no nested CostumeData type, so this returns null when
     * the message is unavailable for the version.
     */
    private function equippedCostumeData(TaikoGameVersion $version, PlayerCosmetic $cosmetic): ?Message
    {
        $costume = $this->messages->tryMake($version, 'BAIDResponse\\CostumeData');
        if (! $costume instanceof Message) {
            return null;
        }

        return $this->writer->fill($costume, [
            'setCostume1' => (int) $cosmetic->costume_1,
            'setCostume2' => (int) $cosmetic->costume_2,
            'setCostume3' => (int) $cosmetic->costume_3,
            'setCostume4' => (int) $cosmetic->costume_4,
            'setCostume5' => (int) $cosmetic->costume_5,
        ]);
    }

    public function baid(Message $request, TaikoGameVersion $version): Message
    {
        $card = GameCard::query()->with('player')->find($request->getAccessCode());

        if (! $card instanceof GameCard || ! $card->player instanceof Player) {
            return $this->baidFailureResponse($version, $request->getAccessCode());
        }

        $this->updateCardMetadata($card, $request);

        return $this->baidResponse(
            $version,
            $card->player,
            $card->access_code,
            empty($card->player->mydon_name),
        );
    }

    public function registerMydon(Message $request, TaikoGameVersion $version): Message
    {
        $card = GameCard::query()->with('player')->find($request->getAccessCode());

        if (! $card instanceof GameCard || ! $card->player instanceof Player) {
            return $this->mydonEntryFailureResponse($version, $request->getAccessCode());
        }

        $this->updateCardMetadata($card, $request);

        $card->player->update([
            'mydon_name' => $request->getMydonName(),
        ]);

        return $this->writer->fill($this->messages->make($version, 'MydonEntryResponse'), [
            'setResult' => 1,
            'setComSvrResult' => 1,
            'setBaid' => $card->player->baid,
            'setAccessCode' => $card->access_code,
            'setIsPublish' => (bool) $card->player->is_publish,
            'setCardOwnNum' => 1,
            'setRegCountryId' => $request->getCountryId() ?: (string) config('taiko_green.country'),
            'setMydonName' => $card->player->mydon_name,
            'setAccesstoken' => $card->player->access_token,
            'setContentInfo' => '',
            'setPersonid' => $card->player->person_id,
        ]);
    }

    private function updateCardMetadata(GameCard $card, Message $request): void
    {
        $card->update([
            'chip_id' => $request->getChipId(),
            'device_type' => (string) $request->getDeviceType(),
            'country_id' => $request->getCountryId(),
        ]);
    }

    public function userData(Player $player, TaikoGameVersion $version): Message
    {
        $cosmetic = PlayerCosmetic::resolve((int) $player->baid, $version);
        $tokkunState = PlayerTokkunState::query()
            ->where('baid', $player->baid)
            ->where('game_version', $version->value)
            ->first();

        $shop = $this->getShopDetails($player, $version);
        $activeSeason = $shop['activeSeason'];
        $unlockedSongIds = $shop['unlockedSongIds'];
        $unlockedToneIds = $shop['unlockedToneIds'];

        $tones = $cosmetic->unlocked_tones ?? [];
        if ($activeSeason) {
            $tones = array_filter($tones, function (int $toneId) use ($activeSeason, $unlockedToneIds): bool {
                $isShopTone = false;
                foreach ($activeSeason['items'] as $item) {
                    if ($item['item_type'] === 2 && $item['item_id'] === $toneId) {
                        $isShopTone = true;
                        break;
                    }
                }
                if ($isShopTone) {
                    return in_array($toneId, $unlockedToneIds, true);
                }

                return true;
            });
        }

        $isLegacy = in_array($version, [
            TaikoGameVersion::Sorairo,
            TaikoGameVersion::Momoiro,
            TaikoGameVersion::Kimidori,
        ], true);

        $optionFlg = $isLegacy
            ? pack('n', (int) $cosmetic->default_option_setting)
            : pack('V', (int) $cosmetic->default_option_setting);

        return $this->writer->fill($this->messages->make($version, 'UserDataResponse'), [
            'setResult' => 1,
            'setIsExplain' => false,
            'setAryFavoriteSongNo' => $player->favorite_song_numbers ?? [],
            'setAryRecentSongNo' => $player->recent_song_numbers ?? [],
            'setSongHashVer' => 99,
            'setHashReleaseSongFlg' => $this->releaseSongFlag($version->value, $activeSeason, $unlockedSongIds),
            'setIsDevil' => false,
            'setDispScoreType' => (int) $player->disp_score_type,
            'setAryFriendInfo' => [],
            'setDispLevelTotal' => 0,
            'setDispLevelChassis' => 0,
            'setOptionFlg' => $optionFlg,
            'setToneFlg' => $this->scoreMapper->idFlagBytes($tones, self::TONE_FLAG_BYTES),
            'setTitleFlg' => $this->scoreMapper->idFlagBytes($cosmetic->unlocked_titles ?? [], self::TITLE_FLAG_BYTES),
            'setSongPushedCnt' => 0,
            'setSongFavoriteCnt' => count($player->favorite_song_numbers ?? []),
            'setSongRecentCnt' => count($player->recent_song_numbers ?? []),
            'setTotalCreditCnt' => (int) $player->total_credit_count,
            'setRecommendSong' => 0,
            'setRecommendBestSong' => [],
            'setDispLevelSelf' => 0,
            'setDefaultOptionSetting' => $optionFlg,
            'setDispTaikojukuDan' => self::SAFE_DISP_TAIKOJUKU_DAN,
            'setDifficultyPlayedCourse' => (int) $player->difficulty_played_course,
            'setDifficultyPlayedStar' => (int) $player->difficulty_played_star,
            'setIsChallengecompe' => false,
            'setIsTojiru' => false,
            'setTokkunTutorialFlg' => (int) ($tokkunState?->tokkun_tutorial_flg ?? 0),
        ]);
    }

    private function releaseSongFlag(string $gameVersion, ?array $activeSeason = null, array $unlockedSongIds = []): string
    {
        $songNumbers = Song::query()
            ->where('version', $gameVersion)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo)
            ->filter(function (int $songNo) use ($activeSeason, $unlockedSongIds): bool {
                if ($activeSeason) {
                    $isShopSong = false;
                    foreach ($activeSeason['items'] as $item) {
                        if ($item['item_type'] === 1 && $item['item_id'] === $songNo) {
                            $isShopSong = true;
                            break;
                        }
                    }
                    if ($isShopSong) {
                        return in_array($songNo, $unlockedSongIds, true);
                    }
                }

                return true;
            })
            ->values()
            ->all();

        return $this->scoreMapper->releaseSongFlagBytes($gameVersion, $songNumbers);
    }

    private function baidFailureResponse(TaikoGameVersion $version, string $accessCode): Message
    {
        return $this->writer->fill($this->messages->make($version, 'BAIDResponse'), [
            'setResult' => 0,
            'setPlayerType' => 1,
            'setComSvrResult' => 0,
            'setBaid' => 0,
            'setAccessCode' => $accessCode,
            'setCardOwnNum' => 0,
            'setRegCountryId' => (string) config('taiko_green.country'),
            'setPurposeId' => 0,
            'setRegionId' => (int) config('taiko_green.region'),
            'setMydonName' => '',
            'setAccesstoken' => '',
            'setContentInfo' => '',
            'setPersonid' => '',
        ]);
    }

    private function mydonEntryFailureResponse(TaikoGameVersion $version, string $accessCode): Message
    {
        return $this->writer->fill($this->messages->make($version, 'MydonEntryResponse'), [
            'setResult' => 0,
            'setComSvrResult' => 0,
            'setBaid' => 0,
            'setAccessCode' => $accessCode,
            'setIsPublish' => false,
            'setCardOwnNum' => 0,
            'setRegCountryId' => (string) config('taiko_green.country'),
            'setMydonName' => '',
            'setAccesstoken' => '',
            'setContentInfo' => '',
            'setPersonid' => '',
        ]);
    }

    private function baidResponse(TaikoGameVersion $version, Player $player, string $accessCode, bool $needsRegistration): Message
    {
        $cosmetic = PlayerCosmetic::resolve((int) $player->baid, $version);
        $shop = $this->getShopDetails($player, $version);
        $activeSeason = $shop['activeSeason'];
        $unlockedCostumeIdsBySlot = $shop['unlockedCostumeIdsBySlot'];

        return $this->writer->fill($this->messages->make($version, 'BAIDResponse'), [
            'setResult' => 1,
            'setPlayerType' => $needsRegistration ? 1 : 0,
            'setComSvrResult' => 1,
            'setBaid' => $player->baid,
            'setAccessCode' => $accessCode,
            'setIsPublish' => (bool) $player->is_publish,
            'setCardOwnNum' => 1,
            'setRegCountryId' => (string) config('taiko_green.country'),
            'setPurposeId' => 0,
            'setRegionId' => $player->prefecture_id > 0 ? (int) $player->prefecture_id : (int) config('taiko_green.region'),
            'setMydonName' => $player->mydon_name ?? '',
            'setTitle' => $cosmetic->title ?? '',
            'setTitleplateId' => (int) $cosmetic->titleplate_id,
            'setColorFace' => (int) $player->color_face,
            'setColorBody' => (int) $player->color_body,
            'setColorLimb' => (int) $player->color_limb,
            'setAryCostumedata' => $this->equippedCostumeData($version, $cosmetic),
            'setAryFavoriteCostumedata' => [],
            'setCostumeFlg' => $this->costumeFlag($cosmetic, 1, $activeSeason, $unlockedCostumeIdsBySlot),
            'setCostumeFlg1' => $this->costumeFlag($cosmetic, 1, $activeSeason, $unlockedCostumeIdsBySlot),
            'setCostumeFlg2' => $this->costumeFlag($cosmetic, 2, $activeSeason, $unlockedCostumeIdsBySlot),
            'setCostumeFlg3' => $this->costumeFlag($cosmetic, 3, $activeSeason, $unlockedCostumeIdsBySlot),
            'setCostumeFlg4' => $this->costumeFlag($cosmetic, 4, $activeSeason, $unlockedCostumeIdsBySlot),
            'setCostumeFlg5' => $this->costumeFlag($cosmetic, 5, $activeSeason, $unlockedCostumeIdsBySlot),
            'setTotalGetDonmedal' => (int) $shop['totalGet'],
            'setTotalUseDonmedal' => (int) $shop['totalUse'],
            'setTotalGetKatsumedal' => (int) $player->total_get_katsumedal,
            'setTotalUseKatsumedal' => (int) $player->total_use_katsumedal,
            'setItemshopTutorialFlg' => (int) $player->item_shop_tutorial_flg,
            'setIsAutoCostumeOn' => false,
            'setLastPlayDatetime' => optional($player->last_played_at)->format('YmdHis') ?? now()->startOfDay()->format('YmdHis'),
            'setUpdateDatetime' => now()->format('YmdHis'),
            'setDispDanType' => (int) $player->disp_dan_type,
            'setGotDanMax' => 0,
            'setGotDanFlg' => $this->scoreMapper->emptyFlagBytes(64),
            'setGotDanextraFlg' => $this->scoreMapper->emptyFlagBytes(64),
            'setAccesstoken' => $player->access_token ?? '',
            'setContentInfo' => '',
            'setDefaultToneSetting' => (int) $cosmetic->default_tone_setting,
            'setPersonid' => $player->person_id ?? '',
            'setWaiwaiTutorialFlg' => (int) $player->waiwai_tutorial_flg,
        ]);
    }
}
