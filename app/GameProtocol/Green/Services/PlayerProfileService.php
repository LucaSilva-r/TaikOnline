<?php

namespace App\GameProtocol\Green\Services;

use App\GameProtocol\Green\Proto\Taiko\BAIDRequest;
use App\GameProtocol\Green\Proto\Taiko\BAIDResponse;
use App\GameProtocol\Green\Proto\Taiko\BAIDResponse\CostumeData;
use App\GameProtocol\Green\Proto\Taiko\MydonEntryRequest;
use App\GameProtocol\Green\Proto\Taiko\MydonEntryResponse;
use App\GameProtocol\Green\Proto\Taiko\UserDataResponse;
use App\GameProtocol\Green\Support\ScoreMapper;
use App\Models\GameCard;
use App\Models\Player;
use Illuminate\Support\Str;

class PlayerProfileService
{
    public function __construct(private readonly ScoreMapper $scoreMapper) {}

    public function baid(BAIDRequest $request): BAIDResponse
    {
        $card = GameCard::query()->with('player')->find($request->getAccessCode());
        $isNew = $card === null;

        if ($isNew) {
            $player = Player::query()->create([
                'access_token' => Str::random(32),
                'person_id' => (string) Str::uuid(),
            ])->refresh();

            $card = GameCard::query()->create([
                'access_code' => $request->getAccessCode(),
                'baid' => $player->baid,
                'chip_id' => $request->getChipId(),
                'device_type' => (string) $request->getDeviceType(),
                'country_id' => $request->getCountryId(),
            ]);
            $card->setRelation('player', $player);
        }

        return $this->baidResponse($card->player, $card->access_code, $isNew);
    }

    public function registerMydon(MydonEntryRequest $request): MydonEntryResponse
    {
        $card = GameCard::query()->with('player')->firstOrCreate(
            ['access_code' => $request->getAccessCode()],
            [
                'baid' => Player::query()->create([
                    'access_token' => Str::random(32),
                    'person_id' => (string) Str::uuid(),
                ])->refresh()->baid,
                'chip_id' => $request->getChipId(),
                'device_type' => (string) $request->getDeviceType(),
                'country_id' => $request->getCountryId(),
            ],
        );

        $card->loadMissing('player');
        $card->player->update([
            'mydon_name' => $request->getMydonName(),
        ]);

        return (new MydonEntryResponse)
            ->setResult(1)
            ->setComSvrResult(1)
            ->setBaid($card->player->baid)
            ->setAccessCode($card->access_code)
            ->setIsPublish(true)
            ->setCardOwnNum(1)
            ->setRegCountryId($request->getCountryId() ?: (string) config('taiko_green.country'))
            ->setMydonName($card->player->mydon_name)
            ->setAccesstoken($card->player->access_token)
            ->setContentInfo('')
            ->setPersonid($card->player->person_id);
    }

    public function userData(Player $player): UserDataResponse
    {
        return (new UserDataResponse)
            ->setResult(1)
            ->setIsExplain(false)
            ->setAryFavoriteSongNo($player->favorite_song_numbers ?? [])
            ->setAryRecentSongNo($player->recent_song_numbers ?? [])
            ->setSongHashVer(1)
            ->setHashReleaseSongFlg($this->scoreMapper->emptyFlagBytes())
            ->setIsDevil(false)
            ->setDispScoreType(0)
            ->setAryFriendInfo([])
            ->setDispLevelTotal(0)
            ->setDispLevelChassis(0)
            ->setOptionFlg(pack('V', (int) $player->default_option_setting))
            ->setToneFlg($this->scoreMapper->emptyFlagBytes())
            ->setTitleFlg($this->scoreMapper->emptyFlagBytes())
            ->setSongPushedCnt(0)
            ->setSongFavoriteCnt(count($player->favorite_song_numbers ?? []))
            ->setSongRecentCnt(count($player->recent_song_numbers ?? []))
            ->setTotalCreditCnt((int) $player->total_credit_count)
            ->setRecommendSong(0)
            ->setRecommendBestSong([])
            ->setDispLevelSelf(0)
            ->setDefaultOptionSetting(pack('V', (int) $player->default_option_setting))
            ->setDefaultShinSetting(false)
            ->setDispTaikojukuDan(0)
            ->setDifficultyPlayedCourse((int) $player->difficulty_played_course)
            ->setDifficultyPlayedStar((int) $player->difficulty_played_star)
            ->setIsChallengecompe(false)
            ->setIsTojiru(false);
    }

    private function baidResponse(Player $player, string $accessCode, bool $isNew): BAIDResponse
    {
        return (new BAIDResponse)
            ->setResult(1)
            ->setPlayerType($isNew ? 1 : 0)
            ->setComSvrResult(1)
            ->setBaid($player->baid)
            ->setAccessCode($accessCode)
            ->setIsPublish(true)
            ->setCardOwnNum(1)
            ->setRegCountryId((string) config('taiko_green.country'))
            ->setPurposeId(0)
            ->setRegionId((int) config('taiko_green.region'))
            ->setMydonName($player->mydon_name ?? '')
            ->setTitle($player->title ?? '')
            ->setTitleplateId((int) $player->titleplate_id)
            ->setColorFace((int) $player->color_face)
            ->setColorBody((int) $player->color_body)
            ->setColorLimb((int) $player->color_limb)
            ->setAryCostumedata(new CostumeData)
            ->setAryFavoriteCostumedata([])
            ->setCostumeFlg1($this->scoreMapper->emptyFlagBytes())
            ->setCostumeFlg2($this->scoreMapper->emptyFlagBytes())
            ->setCostumeFlg3($this->scoreMapper->emptyFlagBytes())
            ->setCostumeFlg4($this->scoreMapper->emptyFlagBytes())
            ->setCostumeFlg5($this->scoreMapper->emptyFlagBytes())
            ->setTotalGetDonmedal((int) $player->total_get_donmedal)
            ->setTotalUseDonmedal((int) $player->total_use_donmedal)
            ->setTotalGetKatsumedal((int) $player->total_get_katsumedal)
            ->setTotalUseKatsumedal((int) $player->total_use_katsumedal)
            ->setItemshopTutorialFlg(0)
            ->setIsAutoCostumeOn(false)
            ->setLastPlayDatetime(optional($player->last_played_at)->format('YmdHis') ?? now()->startOfDay()->format('YmdHis'))
            ->setUpdateDatetime(now()->format('YmdHis'))
            ->setDispDanType(0)
            ->setGotDanMax(0)
            ->setGotDanFlg($this->scoreMapper->emptyFlagBytes(64))
            ->setGotDanextraFlg($this->scoreMapper->emptyFlagBytes(64))
            ->setAccesstoken($player->access_token ?? '')
            ->setContentInfo('')
            ->setDefaultToneSetting((int) $player->default_tone_setting)
            ->setPersonid($player->person_id ?? '')
            ->setWaiwaiTutorialFlg(0);
    }
}
