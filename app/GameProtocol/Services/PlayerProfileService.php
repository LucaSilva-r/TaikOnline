<?php

namespace App\GameProtocol\Services;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\MessageWriter;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ScoreMapper;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
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

    public function __construct(
        private readonly ScoreMapper $scoreMapper,
        private readonly ProtocolMessageResolver $messages,
        private readonly MessageWriter $writer,
    ) {}

    /**
     * Render one costume slot's unlocked ids into its flag bitset. Costumes are
     * stored as a {slot => [ids]} map in the version-scoped cosmetic row.
     */
    private function costumeFlag(PlayerCosmetic $cosmetic, int $slot): string
    {
        $ids = ($cosmetic->unlocked_costumes ?? [])[(string) $slot] ?? [];

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
            'setIsPublish' => true,
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

        return $this->writer->fill($this->messages->make($version, 'UserDataResponse'), [
            'setResult' => 1,
            'setIsExplain' => false,
            'setAryFavoriteSongNo' => $player->favorite_song_numbers ?? [],
            'setAryRecentSongNo' => $player->recent_song_numbers ?? [],
            'setSongHashVer' => 99,
            'setHashReleaseSongFlg' => $this->releaseSongFlag($version->value),
            'setIsDevil' => false,
            'setDispScoreType' => 0,
            'setAryFriendInfo' => [],
            'setDispLevelTotal' => 0,
            'setDispLevelChassis' => 0,
            'setOptionFlg' => pack('V', (int) $cosmetic->default_option_setting),
            'setToneFlg' => $this->scoreMapper->idFlagBytes($cosmetic->unlocked_tones ?? [], self::TONE_FLAG_BYTES),
            'setTitleFlg' => $this->scoreMapper->idFlagBytes($cosmetic->unlocked_titles ?? [], self::TITLE_FLAG_BYTES),
            'setSongPushedCnt' => 0,
            'setSongFavoriteCnt' => count($player->favorite_song_numbers ?? []),
            'setSongRecentCnt' => count($player->recent_song_numbers ?? []),
            'setTotalCreditCnt' => (int) $player->total_credit_count,
            'setRecommendSong' => 0,
            'setRecommendBestSong' => [],
            'setDispLevelSelf' => 0,
            'setDefaultOptionSetting' => pack('V', (int) $cosmetic->default_option_setting),
            'setDefaultShinSetting' => false,
            'setDispTaikojukuDan' => 0,
            'setDifficultyPlayedCourse' => (int) $player->difficulty_played_course,
            'setDifficultyPlayedStar' => (int) $player->difficulty_played_star,
            'setIsChallengecompe' => false,
            'setIsTojiru' => false,
        ]);
    }

    private function releaseSongFlag(string $gameVersion): string
    {
        $songNumbers = Song::query()
            ->where('version', $gameVersion)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo);

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

        return $this->writer->fill($this->messages->make($version, 'BAIDResponse'), [
            'setResult' => 1,
            'setPlayerType' => $needsRegistration ? 1 : 0,
            'setComSvrResult' => 1,
            'setBaid' => $player->baid,
            'setAccessCode' => $accessCode,
            'setIsPublish' => true,
            'setCardOwnNum' => 1,
            'setRegCountryId' => (string) config('taiko_green.country'),
            'setPurposeId' => 0,
            'setRegionId' => (int) config('taiko_green.region'),
            'setMydonName' => $player->mydon_name ?? '',
            'setTitle' => $cosmetic->title ?? '',
            'setTitleplateId' => (int) $cosmetic->titleplate_id,
            'setColorFace' => (int) $player->color_face,
            'setColorBody' => (int) $player->color_body,
            'setColorLimb' => (int) $player->color_limb,
            'setAryCostumedata' => $this->equippedCostumeData($version, $cosmetic),
            'setAryFavoriteCostumedata' => [],
            'setCostumeFlg1' => $this->costumeFlag($cosmetic, 1),
            'setCostumeFlg2' => $this->costumeFlag($cosmetic, 2),
            'setCostumeFlg3' => $this->costumeFlag($cosmetic, 3),
            'setCostumeFlg4' => $this->costumeFlag($cosmetic, 4),
            'setCostumeFlg5' => $this->costumeFlag($cosmetic, 5),
            'setTotalGetDonmedal' => (int) $player->total_get_donmedal,
            'setTotalUseDonmedal' => (int) $player->total_use_donmedal,
            'setTotalGetKatsumedal' => (int) $player->total_get_katsumedal,
            'setTotalUseKatsumedal' => (int) $player->total_use_katsumedal,
            'setItemshopTutorialFlg' => 0,
            'setIsAutoCostumeOn' => false,
            'setLastPlayDatetime' => optional($player->last_played_at)->format('YmdHis') ?? now()->startOfDay()->format('YmdHis'),
            'setUpdateDatetime' => now()->format('YmdHis'),
            'setDispDanType' => 0,
            'setGotDanMax' => 0,
            'setGotDanFlg' => $this->scoreMapper->emptyFlagBytes(64),
            'setGotDanextraFlg' => $this->scoreMapper->emptyFlagBytes(64),
            'setAccesstoken' => $player->access_token ?? '',
            'setContentInfo' => '',
            'setDefaultToneSetting' => (int) $cosmetic->default_tone_setting,
            'setPersonid' => $player->person_id ?? '',
            'setWaiwaiTutorialFlg' => 0,
        ]);
    }
}
