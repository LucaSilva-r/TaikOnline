<?php

namespace App\Http\Controllers\Green;

use App\GameProtocol\Green\Proto\Taiko\BAIDRequest;
use App\GameProtocol\Green\Proto\Taiko\BookKeepingRequest;
use App\GameProtocol\Green\Proto\Taiko\BookKeepingResponse;
use App\GameProtocol\Green\Proto\Taiko\CrownsDataRequest;
use App\GameProtocol\Green\Proto\Taiko\CrownsDataResponse;
use App\GameProtocol\Green\Proto\Taiko\HeadClerk2Request;
use App\GameProtocol\Green\Proto\Taiko\HeadClerk2Response;
use App\GameProtocol\Green\Proto\Taiko\HeartBeatResponse;
use App\GameProtocol\Green\Proto\Taiko\InitialdatacheckResponse;
use App\GameProtocol\Green\Proto\Taiko\MydonEntryRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultResponse;
use App\GameProtocol\Green\Proto\Taiko\SelfBestRequest;
use App\GameProtocol\Green\Proto\Taiko\UserDataRequest;
use App\GameProtocol\Green\Services\PlayerProfileService;
use App\GameProtocol\Green\Services\PlayResultService;
use App\GameProtocol\Green\Support\ProtocolPayloads;
use App\GameProtocol\Green\Support\ScoreMapper;
use App\Http\Controllers\Controller;
use App\Models\CabinetBookkeepingLog;
use App\Models\HeadClerkLog;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GameProtocolController extends Controller
{
    public function __construct(
        private readonly ProtocolPayloads $payloads,
        private readonly PlayerProfileService $profiles,
        private readonly PlayResultService $playResults,
        private readonly ScoreMapper $scoreMapper,
    ) {}

    public function heartbeat(): Response
    {
        return $this->payloads->response(
            (new HeartBeatResponse)
                ->setResult(1)
                ->setComSvrStat(1)
                ->setGameSvrStat(1)
                ->setBnidSvrStat(1)
                ->setBanacoinStat(1)
        );
    }

    public function initialDataCheck(): Response
    {
        return $this->payloads->response(
            (new InitialdatacheckResponse)
                ->setResult(1)
                ->setSongHashVer(1)
                ->setHashDefaultSongFlg($this->scoreMapper->emptyFlagBytes())
                ->setHashMainichidojoAll($this->scoreMapper->emptyFlagBytes(32))
                ->setHashMainichidojoRare($this->scoreMapper->emptyFlagBytes(32))
                ->setAryTelopData([])
                ->setAryEventfolderData([])
                ->setAryTaikojukuData([])
                ->setAryItemshopData([])
                ->setIsDanplay(true)
                ->setIsClose(false)
                ->setIsItemshop(false)
                ->setIsGhostbattleplay(false)
        );
    }

    public function bookKeeping(Request $request): Response
    {
        /** @var BookKeepingRequest $message */
        $message = $this->payloads->parse($request->getContent(), BookKeepingRequest::class);

        CabinetBookkeepingLog::query()->create([
            'chassis_id' => $message->getChassisId(),
            'shop_id' => $message->getShopId(),
            'update_date' => $message->getUpdateDate(),
            'all_play_count' => $message->getAllPlayCnt(),
            'service_switch_count' => $message->getServiceSwCnt(),
            'free_play_count' => $message->getFreePlayCnt(),
            'payload' => [
                'credit_cost_1' => $message->getCreditCost1(),
                'credit_cost_2' => $message->getCreditCost2(),
                'credit_songs_1' => $message->getCreditSongs1(),
                'credit_songs_2' => $message->getCreditSongs2(),
            ],
        ]);

        return $this->payloads->response((new BookKeepingResponse)->setResult(1));
    }

    public function baid(Request $request): Response
    {
        /** @var BAIDRequest $message */
        $message = $this->payloads->parse($request->getContent(), BAIDRequest::class);

        return $this->payloads->response($this->profiles->baid($message));
    }

    public function mydonEntry(Request $request): Response
    {
        /** @var MydonEntryRequest $message */
        $message = $this->payloads->parse($request->getContent(), MydonEntryRequest::class);

        return $this->payloads->response($this->profiles->registerMydon($message));
    }

    public function userData(Request $request): Response
    {
        /** @var UserDataRequest $message */
        $message = $this->payloads->parse($request->getContent(), UserDataRequest::class);
        $player = Player::query()->find($message->getBaid());

        if (! $player instanceof Player) {
            return $this->payloads->response($this->profiles->userData(new Player));
        }

        return $this->payloads->response($this->profiles->userData($player));
    }

    public function playResult(Request $request): Response
    {
        /** @var PlayResultRequest $message */
        $message = $this->payloads->parse($request->getContent(), PlayResultRequest::class);
        /** @var PlayResultDataRequest $data */
        $data = $this->payloads->parse(
            $this->payloads->inflatePlayResultData($message->getPlayresultData()),
            PlayResultDataRequest::class,
        );

        return $this->payloads->response(
            (new PlayResultResponse)->setResult($this->playResults->save($data))
        );
    }

    public function selfBest(Request $request): Response
    {
        /** @var SelfBestRequest $message */
        $message = $this->payloads->parse($request->getContent(), SelfBestRequest::class);
        $player = Player::query()->find($message->getBaid());

        if (! $player instanceof Player) {
            return $this->payloads->response($this->playResults->selfBest(new Player, $message->getLevel(), []));
        }

        return $this->payloads->response(
            $this->playResults->selfBest($player, $message->getLevel(), $message->getArySongNo())
        );
    }

    public function crownsData(Request $request): Response
    {
        /** @var CrownsDataRequest $message */
        $this->payloads->parse($request->getContent(), CrownsDataRequest::class);

        return $this->payloads->response(
            (new CrownsDataResponse)
                ->setResult(1)
                ->setSongHashVer(1)
                ->setHashCrownFlg($this->scoreMapper->emptyFlagBytes())
        );
    }

    public function headClerk2(Request $request): Response
    {
        /** @var HeadClerk2Request $message */
        $message = $this->payloads->parse($request->getContent(), HeadClerk2Request::class);

        foreach ($message->getAryPlayInfo() as $playData) {
            HeadClerkLog::query()->create([
                'chassis_id' => $message->getChassisId(),
                'shop_id' => $message->getShopId(),
                'baid' => $playData->getBaid() ?: null,
                'net_id' => $playData->getNetId(),
                'played_at' => $playData->getPlayedAt() ?: null,
                'is_right' => $playData->getIsRight(),
                'place_id' => $playData->getPlaceId(),
                'type' => $playData->getType(),
                'amount' => $playData->getAmount(),
            ]);
        }

        return $this->payloads->response((new HeadClerk2Response)->setResult(1));
    }
}
