<?php

namespace App\Http\Controllers\Green;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Green\Services\PlayerProfileService;
use App\GameProtocol\Green\Services\PlayResultService;
use App\GameProtocol\Green\Support\MessageWriter;
use App\GameProtocol\Green\Support\ProtocolMessageResolver;
use App\GameProtocol\Green\Support\ProtocolPayloads;
use App\GameProtocol\Green\Support\ScoreMapper;
use App\Http\Controllers\Controller;
use App\Models\CabinetBookkeepingLog;
use App\Models\HeadClerkLog;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongPlayResult;
use App\Services\CabinetService;
use Google\Protobuf\Internal\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GameProtocolController extends Controller
{
    /**
     * Route version used when a request arrives without a version segment
     * (e.g. the bare "/" setup probe). Resolves to the green dialect.
     */
    private const DEFAULT_ROUTE_VERSION = 'v11r00';

    public function __construct(
        private readonly ProtocolPayloads $payloads,
        private readonly ProtocolMessageResolver $messages,
        private readonly MessageWriter $writer,
        private readonly PlayerProfileService $profiles,
        private readonly PlayResultService $playResults,
        private readonly ScoreMapper $scoreMapper,
        private readonly CabinetService $cabinets,
    ) {}

    public function heartbeat(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'HeartBeatRequest');

        $serial = $message->getChassisId();
        if ($serial !== '') {
            $this->cabinets->recordHeartbeat($serial, $request->ip());
        }

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'HeartBeatResponse'), [
                'setResult' => 1,
                'setComSvrStat' => 1,
                'setGameSvrStat' => 1,
                'setBnidSvrStat' => 1,
                'setBanacoinStat' => 1,
            ])
        );
    }

    public function rootSetup(Request $request): Response
    {
        $payload = $request->getContent();

        if ($this->hasProtobufField($payload, 3, 2)) {
            return $this->bookKeeping($request, self::DEFAULT_ROUTE_VERSION);
        }

        if ($this->hasProtobufField($payload, 3, 0)) {
            return $this->getTelop($request, self::DEFAULT_ROUTE_VERSION);
        }

        return $this->initialDataCheck($request, 'v01r00_tw');
    }

    public function initialDataCheck(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'InitialdatacheckRequest');
        $releaseSongFlag = $this->releaseSongFlag($game->value);

        if ($this->usesBlueInitialDataSchema($version)) {
            return $this->blueInitialDataCheckResponse($releaseSongFlag);
        }

        $information = $this->writer->fill($this->messages->make($game, 'InitialdatacheckResponse\\InformationData'), [
            'setInfoId' => 1,
            'setVerupNo' => 2,
        ]);

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'InitialdatacheckResponse'), [
                'setResult' => 1,
                'setSongHashVer' => 99,
                'setHashDefaultSongFlg' => $releaseSongFlag,
                'setAryTelopData' => [$information],
                'setAryEventfolderData' => [],
                'setAryTaikojukuData' => [],
                'setAryItemshopData' => [],
                'setIsDanplay' => true,
                'setIsClose' => false,
                'setIsItemshop' => false,
                'setIsGhostbattleplay' => true,
            ])
        );
    }

    public function bookKeeping(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'BookKeepingRequest');

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

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'BookKeepingResponse'), 'setResult', 1)
        );
    }

    public function baid(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'BAIDRequest');

        return $this->payloads->response($this->profiles->baid($message, $game));
    }

    public function mydonEntry(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'MydonEntryRequest');

        return $this->payloads->response($this->profiles->registerMydon($message, $game));
    }

    public function userData(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'UserDataRequest');
        $player = Player::query()->find($message->getBaid());

        return $this->payloads->response(
            $this->profiles->userData($player instanceof Player ? $player : new Player, $game)
        );
    }

    public function playResult(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'PlayResultRequest');
        $data = $this->payloads->parse(
            $this->payloads->inflatePlayResultData($message->getPlayresultData()),
            $this->messages->class($game, 'PlayResultDataRequest'),
        );

        return $this->payloads->response(
            $this->writer->set(
                $this->messages->make($game, 'PlayResultResponse'),
                'setResult',
                $this->playResults->save($data, $game),
            )
        );
    }

    public function selfBest(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'SelfBestRequest');
        $player = Player::query()->find($message->getBaid());

        return $this->payloads->response(
            $this->playResults->selfBest(
                $player instanceof Player ? $player : new Player,
                $message->getLevel(),
                $game,
                $player instanceof Player ? $message->getArySongNo() : [],
            )
        );
    }

    public function crownsData(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'CrownsDataRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'CrownsDataResponse'), [
                'setResult' => 1,
                'setSongHashVer' => 99,
                'setHashCrownFlg' => $this->scoreMapper->emptyFlagBytes(),
            ])
        );
    }

    public function getFolder(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'GetfolderRequest');

        $folders = collect($message->getFolderId())
            ->map(fn (mixed $folderId): Message => $this->writer->fill(
                $this->messages->make($game, 'GetfolderResponse\\EventfolderData'),
                [
                    'setFolderId' => (int) $folderId,
                    'setSongNo' => [1, 2, 3],
                    'setVerupNo' => 1,
                ],
            ))
            ->all();

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'GetfolderResponse'), [
                'setResult' => 1,
                'setAryEventfolderData' => $folders,
            ])
        );
    }

    public function getTelop(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'GettelopRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'GettelopResponse'), [
                'setResult' => 1,
                'setStartDatetime' => now()->subDays(999)->format('YmdHis'),
                'setEndDatetime' => now()->addDays(999)->format('YmdHis'),
                'setTelop' => 'Hello world',
                'setVerupNo' => 2,
            ])
        );
    }

    public function getGhostData(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'GetghostdataRequest');

        $perfData = $this->writer->fill($this->messages->make($game, 'GetghostdataResponse\\GhostPerfData'), [
            'setInputMedian' => 0,
            'setInputVariance' => 0,
        ]);

        $recordData = $this->writer->fill($this->messages->make($game, 'GetghostdataResponse\\GhostRankData'), [
            'setRankId' => 1,
            'setWinPoint' => 0,
            'setCertifiedLevelId' => 0,
            'setAryWinningsData' => [],
        ]);

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'GetghostdataResponse'), [
                'setResult' => 1,
                'setReleaseInfoFlag' => $this->scoreMapper->emptyFlagBytes(),
                'setPlayedSongFlag' => $this->ghostPlayedSongFlag($game->value),
                'setTotalWinnings' => 0,
                'setGhostPerfData' => $perfData,
                'setGhostRecordData' => $recordData,
                'setAryTokenData' => [],
            ])
        );
    }

    public function getGhostScore(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'GetghostscoreRequest');

        $plays = SongPlayResult::query()
            ->select(['baid', 'score', 'ghost_sections'])
            ->where('game_version', $game->value)
            ->where('song_no', $message->getSongNo())
            ->where('level', $message->getLevel())
            ->whereNotNull('ghost_sections')
            ->orderByDesc('score')
            ->get();

        $allSections = $plays
            ->unique('baid')
            ->map(fn (SongPlayResult $play): array => $play->ghost_sections)
            ->filter(fn (array $sections): bool => $sections !== [])
            ->values()
            ->all();

        if ($allSections === []) {
            return $this->payloads->response(
                $this->writer->fill($this->messages->make($game, 'GetghostscoreResponse'), [
                    'setResult' => 1,
                    'setAryBestSectionData' => [],
                ])
            );
        }

        $sectionCount = max(array_map('count', $allSections));
        $sections = collect(range(0, $sectionCount - 1))
            ->map(function (int $index) use ($allSections, $game): Message {
                $randomKey = array_rand($allSections);
                $section = $allSections[$randomKey][$index] ?? null;

                return $this->writer->fill($this->messages->make($game, 'GetghostscoreResponse\\GhostBestSectionData'), [
                    'setSectionNo' => $index + 1,
                    'setGoodCnt' => $section['good_cnt'] ?? 0,
                    'setOkCnt' => $section['ok_cnt'] ?? 0,
                    'setNgCnt' => $section['ng_cnt'] ?? 0,
                    'setPoundCnt' => $section['pound_cnt'] ?? 0,
                ]);
            })
            ->all();

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'GetghostscoreResponse'), [
                'setResult' => 1,
                'setAryBestSectionData' => $sections,
            ])
        );
    }

    public function recommend(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'RecommendRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'RecommendResponse'), 'setResult', 1)
        );
    }

    public function tournamentCheck(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'TournamentcheckRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'TournamentcheckResponse'), 'setResult', 1)
        );
    }

    public function challengeCompe(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'ChallengeCompeRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'ChallengeCompeResponse'), 'setResult', 1)
        );
    }

    public function rewardCardCheck(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'RewardcardcheckRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'RewardcardcheckResponse'), 'setResult', 1)
        );
    }

    public function rewardExecution(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $this->parse($request, $game, 'RewardexecutionRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'RewardexecutionResponse'), 'setResult', 1)
        );
    }

    public function headClerk2(Request $request, string $version): Response
    {
        $game = $this->version($version);
        $message = $this->parse($request, $game, 'HeadClerk2Request');

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

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'HeadClerk2Response'), 'setResult', 1)
        );
    }

    /**
     * Resolve the game version from the cabinet's route segment, defaulting to
     * the green dialect when the version cannot be matched.
     */
    private function version(string $routeVersion): TaikoGameVersion
    {
        return TaikoGameVersion::fromRouteVersion($routeVersion) ?? TaikoGameVersion::Green;
    }

    /**
     * Parse the request body into the named message for the given version.
     */
    private function parse(Request $request, TaikoGameVersion $version, string $name): Message
    {
        return $this->payloads->parse($request->getContent(), $this->messages->class($version, $name));
    }

    private function usesBlueInitialDataSchema(string $routeVersion): bool
    {
        return str_starts_with($routeVersion, 'v10');
    }

    private function hasProtobufField(string $payload, int $fieldNumber, int $wireType): bool
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $key = $this->readProtobufVarint($payload, $offset);
            if ($key === null) {
                return false;
            }

            $field = $key >> 3;
            $wire = $key & 0x07;

            if ($field === $fieldNumber && $wire === $wireType) {
                return true;
            }

            if (! $this->skipProtobufValue($payload, $offset, $wire)) {
                return false;
            }
        }

        return false;
    }

    private function skipProtobufValue(string $payload, int &$offset, int $wireType): bool
    {
        return match ($wireType) {
            0 => $this->readProtobufVarint($payload, $offset) !== null,
            1 => $this->skipBytes($payload, $offset, 8),
            2 => $this->skipLengthDelimited($payload, $offset),
            5 => $this->skipBytes($payload, $offset, 4),
            default => false,
        };
    }

    private function skipLengthDelimited(string $payload, int &$offset): bool
    {
        $length = $this->readProtobufVarint($payload, $offset);
        if ($length === null) {
            return false;
        }

        return $this->skipBytes($payload, $offset, $length);
    }

    private function skipBytes(string $payload, int &$offset, int $bytes): bool
    {
        if ($bytes < 0 || $offset + $bytes > strlen($payload)) {
            return false;
        }

        $offset += $bytes;

        return true;
    }

    private function readProtobufVarint(string $payload, int &$offset): ?int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($payload);

        while ($offset < $length && $shift < 64) {
            $byte = ord($payload[$offset]);
            $offset++;
            $result |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $result;
            }

            $shift += 7;
        }

        return null;
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
        // TEST: report every possible song (4096 bits) as available so custom
        // injected songs (e.g. uniqueid 1000) pass the carousel enable gate.
        return str_repeat("\xFF", 512);

        $songNumbers = Song::query()
            ->where('version', $gameVersion)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo);

        return $this->scoreMapper->songFlagBytes($songNumbers);
    }
}
