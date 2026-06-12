<?php

namespace App\GameProtocol\Handlers;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Services\PlayerProfileService;
use App\GameProtocol\Services\PlayResultService;
use App\GameProtocol\Support\MessageWriter;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ProtocolPayloads;
use App\GameProtocol\Support\ScoreMapper;
use App\Models\CabinetBookkeepingLog;
use App\Models\DanCourse;
use App\Models\DanCourseSong;
use App\Models\HeadClerkLog;
use App\Models\Player;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Services\CabinetService;
use Google\Protobuf\Internal\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Default per-version request handler. Holds the response-building logic shared
 * by every Taiko dialect; version-specific handlers extend this class and
 * override only the endpoints whose protobuf shape diverges.
 */
class GameHandler
{
    public function __construct(
        protected readonly ProtocolPayloads $payloads,
        protected readonly ProtocolMessageResolver $messages,
        protected readonly MessageWriter $writer,
        protected readonly PlayerProfileService $profiles,
        protected readonly PlayResultService $playResults,
        protected readonly ScoreMapper $scoreMapper,
        protected readonly CabinetService $cabinets,
    ) {}

    public function heartbeat(Request $request, TaikoGameVersion $game): Response
    {
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

    public function initialDataCheck(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'InitialdatacheckRequest');
        $releaseSongFlag = $this->releaseSongFlag($game->value);

        $information = $this->writer->fill($this->messages->make($game, 'InitialdatacheckResponse\\InformationData'), [
            'setInfoId' => 1,
            'setVerupNo' => 2,
        ]);

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'InitialdatacheckResponse'), [
                'setResult' => 1,
                'setSongHashVer' => (int) $request->attributes->get('songHashVersion', 1),
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

    public function bookKeeping(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'BookKeepingRequest');

        CabinetBookkeepingLog::query()->create([
            'chassis_id' => $message->getChassisId(),
            'shop_id' => $message->getShopId(),
            'update_date' => $message->getUpdateDate(),
            'all_play_count' => $this->read($message, 'getAllPlayCnt', 'getAppPlayCnt'),
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

    public function baid(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'BAIDRequest');

        return $this->payloads->response($this->profiles->baid($message, $game));
    }

    public function mydonEntry(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'MydonEntryRequest');

        return $this->payloads->response($this->profiles->registerMydon($message, $game));
    }

    public function userData(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'UserDataRequest');
        $player = Player::query()->find($message->getBaid());

        return $this->payloads->response(
            $this->profiles->userData($player instanceof Player ? $player : new Player, $game)
        );
    }

    public function playResult(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'PlayResultRequest');

        // Green wraps the play data in a compressed `playresultData` blob, while
        // Blue/Red inline every field directly on the PlayResultRequest message.
        $data = method_exists($message, 'getPlayresultData')
            ? $this->payloads->parse(
                $this->payloads->inflatePlayResultData($message->getPlayresultData()),
                $this->messages->class($game, 'PlayResultDataRequest'),
            )
            : $message;

        return $this->payloads->response(
            $this->writer->set(
                $this->messages->make($game, 'PlayResultResponse'),
                'setResult',
                $this->playResults->save($data, $game),
            )
        );
    }

    public function selfBest(Request $request, TaikoGameVersion $game): Response
    {
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

    public function crownsData(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'CrownsDataRequest');

        $bests = SongBest::query()
            ->select(['song_no', 'level', 'best_crown'])
            ->where('baid', $message->getBaid())
            ->where('game_version', $game->value)
            ->where('best_crown', '>', 0)
            ->get();

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'CrownsDataResponse'), [
                'setResult' => 1,
                'setSongHashVer' => 99,
                'setHashCrownFlg' => $this->scoreMapper->crownFlagBytes($bests),
            ])
        );
    }

    public function getFolder(Request $request, TaikoGameVersion $game): Response
    {
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

    public function getTelop(Request $request, TaikoGameVersion $game): Response
    {
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

    public function songHash(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'SonghashRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'SonghashResponse'), [
                'setResult' => 1,
                'setSongHashVer' => 99,
                'setSongHashTbl' => '',
            ])
        );
    }

    public function defaultSong(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'DefaultsongRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'DefaultsongResponse'), [
                'setResult' => 1,
                'setSongHashVer' => 99,
                'setHashDefaultSongFlg' => $this->releaseSongFlag($game->value),
            ])
        );
    }

    public function folderCheck(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'FoldercheckRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'FoldercheckResponse'), [
                'setResult' => 1,
                'setFolderId' => [],
            ])
        );
    }

    public function telopCheck(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'TelopcheckRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'TelopcheckResponse'), [
                'setResult' => 1,
                'setTelopId' => [],
            ])
        );
    }

    public function taikojuku(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'TaikojukuRequest');

        $requested = collect($message->getGetDan())
            ->map(fn (mixed $dan): int => (int) $dan)
            ->filter(fn (int $dan): bool => $dan >= 1 && $dan <= 25)
            ->values();

        $query = DanCourse::query()
            ->with('songs')
            ->where('version', $game->value)
            ->orderBy('dan');

        if ($requested->isNotEmpty()) {
            $query->whereIn('dan', $requested->all());
        }

        $packs = $query->get()
            ->map(fn (DanCourse $course): Message => $this->writer->fill(
                $this->messages->make($game, 'TaikojukuResponse\\JukupackData'),
                [
                    'setGetDan' => (int) $course->dan,
                    'setVerupNo' => (int) $course->verup_no,
                    'setAryJukusongData' => $course->songs
                        ->map(fn (DanCourseSong $song): Message => $this->writer->fill(
                            $this->messages->make($game, 'TaikojukuResponse\\JukupackData\\JukusongData'),
                            [
                                'setSongNo' => (int) $song->song_no,
                                'setLevel' => (int) $song->level,
                            ],
                        ))
                        ->all(),
                ],
            ))
            ->all();

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'TaikojukuResponse'), [
                'setResult' => 1,
                'setAryJukupackData' => $packs,
            ])
        );
    }

    public function getGhostData(Request $request, TaikoGameVersion $game): Response
    {
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

    public function getGhostScore(Request $request, TaikoGameVersion $game): Response
    {
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

    public function recommend(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'RecommendRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'RecommendResponse'), 'setResult', 1)
        );
    }

    public function tournamentCheck(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'TournamentcheckRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'TournamentcheckResponse'), 'setResult', 1)
        );
    }

    public function challengeCompe(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'ChallengeCompeRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'ChallengeCompeResponse'), 'setResult', 1)
        );
    }

    public function rewardCardCheck(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'RewardcardcheckRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'RewardcardcheckResponse'), 'setResult', 1)
        );
    }

    public function rewardExecution(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'RewardexecutionRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'RewardexecutionResponse'), 'setResult', 1)
        );
    }

    public function headClerk2(Request $request, TaikoGameVersion $game): Response
    {
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
     * Parse the request body into the named message for the given version.
     */
    protected function parse(Request $request, TaikoGameVersion $version, string $name): Message
    {
        return $this->payloads->parse($request->getContent(), $this->messages->class($version, $name));
    }

    /**
     * Read the first getter that exists on a message, tolerating field renames
     * between versions (e.g. all_play_cnt vs app_play_cnt).
     */
    protected function read(Message $message, string ...$getters): mixed
    {
        foreach ($getters as $getter) {
            if (method_exists($message, $getter)) {
                return $message->{$getter}();
            }
        }

        return null;
    }

    protected function ghostPlayedSongFlag(string $gameVersion): string
    {
        $songNumbers = SongPlayResult::query()
            ->where('game_version', $gameVersion)
            ->whereNotNull('ghost_sections')
            ->distinct()
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo);

        return $this->scoreMapper->songFlagBytes($songNumbers);
    }

    protected function releaseSongFlag(string $gameVersion): string
    {
        $songNumbers = Song::query()
            ->where('version', $gameVersion)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo);

        return $this->scoreMapper->songFlagBytes($songNumbers);
    }
}
