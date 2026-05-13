<?php

namespace App\Http\Controllers\Green;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Green\Proto\Taiko\BAIDRequest;
use App\GameProtocol\Green\Proto\Taiko\BookKeepingRequest;
use App\GameProtocol\Green\Proto\Taiko\BookKeepingResponse;
use App\GameProtocol\Green\Proto\Taiko\ChallengeCompeRequest;
use App\GameProtocol\Green\Proto\Taiko\ChallengeCompeResponse;
use App\GameProtocol\Green\Proto\Taiko\CrownsDataRequest;
use App\GameProtocol\Green\Proto\Taiko\CrownsDataResponse;
use App\GameProtocol\Green\Proto\Taiko\GetfolderRequest;
use App\GameProtocol\Green\Proto\Taiko\GetfolderResponse;
use App\GameProtocol\Green\Proto\Taiko\GetfolderResponse\EventfolderData;
use App\GameProtocol\Green\Proto\Taiko\GetghostdataRequest;
use App\GameProtocol\Green\Proto\Taiko\GetghostdataResponse;
use App\GameProtocol\Green\Proto\Taiko\GetghostdataResponse\GhostPerfData;
use App\GameProtocol\Green\Proto\Taiko\GetghostdataResponse\GhostRankData;
use App\GameProtocol\Green\Proto\Taiko\GetghostscoreRequest;
use App\GameProtocol\Green\Proto\Taiko\GetghostscoreResponse;
use App\GameProtocol\Green\Proto\Taiko\GetghostscoreResponse\GhostBestSectionData;
use App\GameProtocol\Green\Proto\Taiko\GettelopRequest;
use App\GameProtocol\Green\Proto\Taiko\GettelopResponse;
use App\GameProtocol\Green\Proto\Taiko\HeadClerk2Request;
use App\GameProtocol\Green\Proto\Taiko\HeadClerk2Response;
use App\GameProtocol\Green\Proto\Taiko\HeartBeatRequest;
use App\GameProtocol\Green\Proto\Taiko\HeartBeatResponse;
use App\GameProtocol\Green\Proto\Taiko\InitialdatacheckRequest;
use App\GameProtocol\Green\Proto\Taiko\InitialdatacheckResponse;
use App\GameProtocol\Green\Proto\Taiko\InitialdatacheckResponse\InformationData;
use App\GameProtocol\Green\Proto\Taiko\MydonEntryRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultResponse;
use App\GameProtocol\Green\Proto\Taiko\RecommendRequest;
use App\GameProtocol\Green\Proto\Taiko\RecommendResponse;
use App\GameProtocol\Green\Proto\Taiko\RewardcardcheckRequest;
use App\GameProtocol\Green\Proto\Taiko\RewardcardcheckResponse;
use App\GameProtocol\Green\Proto\Taiko\RewardexecutionRequest;
use App\GameProtocol\Green\Proto\Taiko\RewardexecutionResponse;
use App\GameProtocol\Green\Proto\Taiko\SelfBestRequest;
use App\GameProtocol\Green\Proto\Taiko\TournamentcheckRequest;
use App\GameProtocol\Green\Proto\Taiko\TournamentcheckResponse;
use App\GameProtocol\Green\Proto\Taiko\UserDataRequest;
use App\GameProtocol\Green\Services\PlayerProfileService;
use App\GameProtocol\Green\Services\PlayResultService;
use App\GameProtocol\Green\Support\ProtocolPayloads;
use App\GameProtocol\Green\Support\ScoreMapper;
use App\Http\Controllers\Controller;
use App\Models\CabinetBookkeepingLog;
use App\Models\HeadClerkLog;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongPlayResult;
use App\Services\CabinetService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

class GameProtocolController extends Controller
{
    public function __construct(
        private readonly ProtocolPayloads $payloads,
        private readonly PlayerProfileService $profiles,
        private readonly PlayResultService $playResults,
        private readonly ScoreMapper $scoreMapper,
        private readonly CabinetService $cabinets,
    ) {}

    public function heartbeat(Request $request): Response
    {
        /** @var HeartBeatRequest $message */
        $message = $this->payloads->parse($request->getContent(), HeartBeatRequest::class);

        $serial = $message->getChassisId();
        if ($serial !== '') {
            $this->cabinets->recordHeartbeat($serial, $request->ip());
        }

        return $this->payloads->response(
            (new HeartBeatResponse)
                ->setResult(1)
                ->setComSvrStat(1)
                ->setGameSvrStat(1)
                ->setBnidSvrStat(1)
                ->setBanacoinStat(1)
        );
    }

    public function initialDataCheck(Request $request, string $version): Response
    {
        /** @var InitialdatacheckRequest $message */
        $this->payloads->parse($request->getContent(), InitialdatacheckRequest::class);
        $releaseSongFlag = $this->releaseSongFlag($this->catalogVersion($version));

        if ($this->usesBlueInitialDataSchema($version)) {
            return $this->blueInitialDataCheckResponse($releaseSongFlag);
        }

        return $this->payloads->response(
            (new InitialdatacheckResponse)
                ->setResult(1)
                ->setSongHashVer(1)
                ->setHashDefaultSongFlg($releaseSongFlag)
                ->setAryTelopData([(new InformationData)->setInfoId(1)->setVerupNo(2)])
                ->setAryEventfolderData([])
                ->setAryTaikojukuData([])
                ->setAryItemshopData([])
                ->setIsDanplay(true)
                ->setIsClose(false)
                ->setIsItemshop(false)
                ->setIsGhostbattleplay(true)
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

    public function userData(Request $request, string $version): Response
    {
        /** @var UserDataRequest $message */
        $message = $this->payloads->parse($request->getContent(), UserDataRequest::class);
        $player = Player::query()->find($message->getBaid());
        $catalogVersion = $this->catalogVersion($version);

        if (! $player instanceof Player) {
            return $this->payloads->response($this->profiles->userData(new Player, $catalogVersion));
        }

        return $this->payloads->response($this->profiles->userData($player, $catalogVersion));
    }

    public function playResult(Request $request, string $version): Response
    {
        /** @var PlayResultRequest $message */
        $message = $this->payloads->parse($request->getContent(), PlayResultRequest::class);
        /** @var PlayResultDataRequest $data */
        $data = $this->payloads->parse(
            $this->payloads->inflatePlayResultData($message->getPlayresultData()),
            PlayResultDataRequest::class,
        );

        return $this->payloads->response(
            (new PlayResultResponse)->setResult($this->playResults->save($data, $this->catalogVersion($version)))
        );
    }

    public function selfBest(Request $request, string $version): Response
    {
        /** @var SelfBestRequest $message */
        $message = $this->payloads->parse($request->getContent(), SelfBestRequest::class);
        $player = Player::query()->find($message->getBaid());
        $catalogVersion = $this->catalogVersion($version);

        if (! $player instanceof Player) {
            return $this->payloads->response($this->playResults->selfBest(new Player, $message->getLevel(), $catalogVersion, []));
        }

        return $this->payloads->response(
            $this->playResults->selfBest($player, $message->getLevel(), $catalogVersion, $message->getArySongNo())
        );
    }

    private function catalogVersion(string $routeVersion): string
    {
        $configured = Config::get("taiko_green.route_catalog_versions.{$routeVersion}");

        if (! is_string($configured) || $configured === '') {
            preg_match('/^(v\d{2})r\d{2}$/', $routeVersion, $matches);

            if (isset($matches[1])) {
                $configured = Config::get("taiko_green.route_catalog_versions.{$matches[1]}");
            }
        }

        if (is_string($configured) && $configured !== '') {
            return TaikoGameVersion::fromInput($configured)?->value ?? TaikoGameVersion::Green->value;
        }

        $default = Config::get('taiko_green.catalog_version', TaikoGameVersion::Green->value);

        return is_string($default)
            ? TaikoGameVersion::fromInput($default)?->value ?? TaikoGameVersion::Green->value
            : TaikoGameVersion::Green->value;
    }

    private function usesBlueInitialDataSchema(string $routeVersion): bool
    {
        return str_starts_with($routeVersion, 'v10');
    }

    private function blueInitialDataCheckResponse(string $releaseSongFlag): Response
    {
        $body = $this->protobufVarintField(1, 1)
            .$this->protobufVarintField(2, 1)
            .$this->protobufBytesField(3, $releaseSongFlag)
            .$this->protobufBytesField(4, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(5, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(6, $this->protobufMessage([
                $this->protobufVarintField(1, 1),
                $this->protobufVarintField(2, 2),
            ]))
            .$this->protobufVarintField(10, 1)
            .$this->protobufVarintField(11, 0)
            .$this->protobufVarintField(12, 0)
            .$this->protobufVarintField(14, 1)
            .$this->protobufBytesField(15, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(16, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufVarintField(17, 0);

        return response($body, 200, ['Content-Type' => 'application/protobuf']);
    }

    private function protobufVarintField(int $field, int $value): string
    {
        return $this->protobufVarint(($field << 3) | 0).$this->protobufVarint($value);
    }

    private function protobufBytesField(int $field, string $value): string
    {
        return $this->protobufVarint(($field << 3) | 2).$this->protobufVarint(strlen($value)).$value;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function protobufMessage(array $fields): string
    {
        return implode('', $fields);
    }

    private function protobufVarint(int $value): string
    {
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;

            if ($value !== 0) {
                $byte |= 0x80;
            }

            $bytes .= chr($byte);
        } while ($value !== 0);

        return $bytes;
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

    public function getFolder(Request $request): Response
    {
        /** @var GetfolderRequest $message */
        $message = $this->payloads->parse($request->getContent(), GetfolderRequest::class);

        $folders = collect($message->getFolderId())
            ->map(fn (mixed $folderId): EventfolderData => (new EventfolderData)
                ->setFolderId((int) $folderId)
                ->setSongNo([1, 2, 3])
                ->setVerupNo(1))
            ->all();

        return $this->payloads->response(
            (new GetfolderResponse)
                ->setResult(1)
                ->setAryEventfolderData($folders)
        );
    }

    public function getTelop(Request $request): Response
    {
        /** @var GettelopRequest $message */
        $this->payloads->parse($request->getContent(), GettelopRequest::class);

        return $this->payloads->response(
            (new GettelopResponse)
                ->setResult(1)
                ->setStartDatetime(now()->subDays(999)->format('YmdHis'))
                ->setEndDatetime(now()->addDays(999)->format('YmdHis'))
                ->setTelop('Hello world')
                ->setVerupNo(2)
        );
    }

    public function getGhostData(Request $request, string $version): Response
    {
        /** @var GetghostdataRequest $message */
        $this->payloads->parse($request->getContent(), GetghostdataRequest::class);

        return $this->payloads->response(
            (new GetghostdataResponse)
                ->setResult(1)
                ->setReleaseInfoFlag($this->scoreMapper->emptyFlagBytes())
                ->setPlayedSongFlag($this->ghostPlayedSongFlag($this->catalogVersion($version)))
                ->setTotalWinnings(0)
                ->setGhostPerfData((new GhostPerfData)->setInputMedian(0)->setInputVariance(0))
                ->setGhostRecordData((new GhostRankData)
                    ->setRankId(1)
                    ->setWinPoint(0)
                    ->setCertifiedLevelId(0)
                    ->setAryWinningsData([]))
                ->setAryTokenData([])
        );
    }

    public function getGhostScore(Request $request, string $version): Response
    {
        /** @var GetghostscoreRequest $message */
        $message = $this->payloads->parse($request->getContent(), GetghostscoreRequest::class);

        $plays = SongPlayResult::query()
            ->select(['baid', 'score', 'ghost_sections'])
            ->where('game_version', $this->catalogVersion($version))
            ->where('song_no', $message->getSongNo())
            ->where('level', $message->getLevel())
            ->whereNotNull('ghost_sections')
            ->orderByDesc('score')
            ->get();

        if ($plays->isEmpty()) {
            return $this->payloads->response(
                (new GetghostscoreResponse)
                    ->setResult(1)
                    ->setAryBestSectionData([])
            );
        }

        $allSections = $plays
            ->unique('baid')
            ->map(fn (SongPlayResult $play): array => $play->ghost_sections)
            ->filter(fn (array $sections): bool => $sections !== [])
            ->values()
            ->all();

        if ($allSections === []) {
            return $this->payloads->response(
                (new GetghostscoreResponse)
                    ->setResult(1)
                    ->setAryBestSectionData([])
            );
        }

        $sectionCount = max(array_map('count', $allSections));
        $sections = collect(range(0, $sectionCount - 1))
            ->map(function (int $index) use ($allSections): GhostBestSectionData {
                $randomKey = array_rand($allSections);
                $section = $allSections[$randomKey][$index] ?? null;

                return (new GhostBestSectionData)
                    ->setSectionNo($index + 1)
                    ->setGoodCnt($section['good_cnt'] ?? 0)
                    ->setOkCnt($section['ok_cnt'] ?? 0)
                    ->setNgCnt($section['ng_cnt'] ?? 0)
                    ->setPoundCnt($section['pound_cnt'] ?? 0);
            })
            ->all();

        return $this->payloads->response(
            (new GetghostscoreResponse)
                ->setResult(1)
                ->setAryBestSectionData($sections)
        );
    }

    private function ghostPlayedSongFlag(string $gameVersion): string
    {
        $songNumbers = SongPlayResult::query()
            ->where('game_version', $gameVersion)
            ->whereNotNull('ghost_sections')
            ->distinct()
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo);

        return $this->scoreMapper->songFlagBytes($songNumbers);
    }

    private function releaseSongFlag(string $gameVersion): string
    {
        $songNumbers = Song::query()
            ->where('version', $gameVersion)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo);

        return $this->scoreMapper->songFlagBytes($songNumbers);
    }

    public function recommend(Request $request): Response
    {
        /** @var RecommendRequest $message */
        $this->payloads->parse($request->getContent(), RecommendRequest::class);

        return $this->payloads->response((new RecommendResponse)->setResult(1));
    }

    public function tournamentCheck(Request $request): Response
    {
        /** @var TournamentcheckRequest $message */
        $this->payloads->parse($request->getContent(), TournamentcheckRequest::class);

        return $this->payloads->response((new TournamentcheckResponse)->setResult(1));
    }

    public function challengeCompe(Request $request): Response
    {
        /** @var ChallengeCompeRequest $message */
        $this->payloads->parse($request->getContent(), ChallengeCompeRequest::class);

        return $this->payloads->response((new ChallengeCompeResponse)->setResult(1));
    }

    public function rewardCardCheck(Request $request): Response
    {
        /** @var RewardcardcheckRequest $message */
        $this->payloads->parse($request->getContent(), RewardcardcheckRequest::class);

        return $this->payloads->response((new RewardcardcheckResponse)->setResult(1));
    }

    public function rewardExecution(Request $request): Response
    {
        /** @var RewardexecutionRequest $message */
        $this->payloads->parse($request->getContent(), RewardexecutionRequest::class);

        return $this->payloads->response((new RewardexecutionResponse)->setResult(1));
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
