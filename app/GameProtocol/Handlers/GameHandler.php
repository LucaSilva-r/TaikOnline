<?php

namespace App\GameProtocol\Handlers;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Services\PlayerProfileService;
use App\GameProtocol\Services\PlayResultService;
use App\GameProtocol\Support\ItemShopCatalog;
use App\GameProtocol\Support\MessageWriter;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ProtocolPayloads;
use App\GameProtocol\Support\ScoreMapper;
use App\Models\CabinetBookkeepingLog;
use App\Models\DanCourse;
use App\Models\DanCourseSong;
use App\Models\GameCard;
use App\Models\HeadClerkLog;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerDonPointState;
use App\Models\PlayerGreenGhostState;
use App\Models\PlayerGreenGhostToken;
use App\Models\PlayerGreenGhostWinnings;
use App\Models\PlayerShopItem;
use App\Models\PlayerShopSeasonState;
use App\Models\Song;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use App\Services\CabinetService;
use App\Services\ExtraScoreService;
use Google\Protobuf\Internal\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Default per-version request handler. Holds the response-building logic shared
 * by every Taiko dialect; version-specific handlers extend this class and
 * override only the endpoints whose protobuf shape diverges.
 */
class GameHandler
{
    private const MAX_TAIKOJUKU_REQUEST_SLOTS = 11;

    private const MAX_TAIKOJUKU_SONGS = 10;

    private const MIN_NORMAL_DAN = 1;

    private const MAX_NORMAL_DAN = 25;

    private const MIN_COURSE_LEVEL = 1;

    private const MAX_COURSE_LEVEL = 5;

    public function __construct(
        protected readonly ProtocolPayloads $payloads,
        protected readonly ProtocolMessageResolver $messages,
        protected readonly MessageWriter $writer,
        protected readonly PlayerProfileService $profiles,
        protected readonly PlayResultService $playResults,
        protected readonly ScoreMapper $scoreMapper,
        protected readonly CabinetService $cabinets,
        protected readonly ExtraScoreService $extraScores,
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

        $catalog = new ItemShopCatalog($game);
        $activeSeason = $catalog->getActiveSeason();
        $isItemShop = ($catalog->isEnabled && $activeSeason) ? true : false;

        $releaseSongFlag = $this->releaseSongFlag($game->value, $activeSeason);

        $information = $this->writer->fill($this->messages->make($game, 'InitialdatacheckResponse\\InformationData'), [
            'setInfoId' => 1,
            'setVerupNo' => 2,
        ]);

        $itemShopData = [];
        if ($isItemShop) {
            $itemShopData[] = $this->writer->fill($this->messages->make($game, 'InitialdatacheckResponse\\InformationData'), [
                'setInfoId' => $activeSeason['season_id'],
                'setVerupNo' => $activeSeason['verup_no'],
            ]);
        }

        $taikojukuData = collect($this->taikojukuInformationRows($game))
            ->map(fn (array $row): Message => $this->writer->fill(
                $this->messages->make($game, 'InitialdatacheckResponse\\InformationData'),
                [
                    'setInfoId' => $row['dan'],
                    'setVerupNo' => $row['verup_no'],
                ],
            ))
            ->all();

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'InitialdatacheckResponse'), [
                'setResult' => 1,
                'setSongHashVer' => (int) $request->attributes->get('songHashVersion', 1),
                'setHashDefaultSongFlg' => $releaseSongFlag,
                // AC15 dialects (red/blue/green) carry zeroed mainichi-dojo
                // bitsets here; older protos lack the fields and no-op.
                'setHashMainichidojoAll' => $this->scoreMapper->emptyFlagBytes(128),
                'setHashMainichidojoRare' => $this->scoreMapper->emptyFlagBytes(128),
                'setAryTelopData' => [$information],
                'setAryEventfolderData' => [],
                'setAryTaikojukuData' => $taikojukuData,
                'setAryItemshopData' => $itemShopData,
                'setIsDanplay' => true,
                'setIsClose' => false,
                'setIsItemshop' => $isItemShop,
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

    public function coinSetting(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'CoinsettingRequest');

        $serial = $message->getChassisId();
        if ($serial !== '') {
            $this->cabinets->recordHeartbeat($serial, $request->ip());
        }

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'CoinsettingResponse'), 'setResult', 1)
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
                $this->playResults->save($data, $game, $this->extraScores->associations($request)),
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

    public function mainichiSong(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'MainichisongRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'MainichisongResponse'), [
                'setResult' => 1,
                'setSongHashVer' => 99,
                'setHashMainichidojoAll' => $this->scoreMapper->emptyFlagBytes(128),
                'setHashMainichidojoRare' => $this->scoreMapper->emptyFlagBytes(128),
            ])
        );
    }

    public function bestScore(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'BestScoreRequest');
        $seqId = method_exists($message, 'getSeqId') ? (int) $message->getSeqId() : 0;

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'BestScoreResponse'), [
                'setResult' => 1,
                'setSeqId' => $seqId,
                'setLastSeqId' => $seqId,
                'setArySongScore' => [],
            ])
        );
    }

    public function communicationLog(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'CommunicationLogRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'CommunicationLogResponse'), 'setResult', 1)
        );
    }

    public function shoppingResult(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'ShoppingResultRequest');
        $player = Player::query()->find($message->getBaid());

        if (! $player instanceof Player) {
            return $this->payloads->response($this->shoppingResultResponse($game));
        }

        DB::transaction(function () use ($player, $message, $game): void {
            $donPointState = PlayerDonPointState::resolve($player->baid, $game);
            $donPointState->total_use_donpoint = (int) $donPointState->total_use_donpoint + (int) $message->getUseDonpoint();
            $donPointState->save();

            $player->unlocked_song_numbers = $this->mergeIds($player->unlocked_song_numbers ?? [], $message->getAryShoppingSongNo());
            $player->save();

            $cosmetic = PlayerCosmetic::resolve($player->baid, $game);
            $cosmetic->unlocked_tones = $this->mergeIds($cosmetic->unlocked_tones ?? [], $this->scoreMapper->flagBytesToIds($message->getToneFlg()));

            $costumes = $cosmetic->unlocked_costumes ?? [];
            for ($slot = 1; $slot <= 3; $slot++) {
                $getter = "getCostumeFlg{$slot}";
                $costumes[(string) $slot] = $this->mergeIds(
                    $costumes[(string) $slot] ?? [],
                    $this->scoreMapper->flagBytesToIds($message->{$getter}()),
                );
            }
            $cosmetic->unlocked_costumes = $costumes;
            $cosmetic->save();
        });

        return $this->payloads->response($this->shoppingResultResponse($game, $player));
    }

    public function songInfo(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'SongInfoRequest');
        $songNumbers = collect($message->getArySongNo())
            ->map(fn (mixed $songNo): int => (int) $songNo)
            ->unique()
            ->values();

        $bests = SongBest::query()
            ->select(['song_no', 'level', 'best_score'])
            ->where('baid', $message->getBaid())
            ->where('game_version', $game->value)
            ->whereIn('song_no', $songNumbers)
            ->get()
            ->groupBy('song_no');

        $groups = $songNumbers
            ->map(function (int $songNo) use ($bests, $game): Message {
                $scores = array_fill(0, 5, 0);
                foreach ($bests->get($songNo, collect()) as $best) {
                    $level = (int) $best->level;
                    if ($level >= 0 && $level < count($scores)) {
                        $scores[$level] = (int) $best->best_score;
                    }
                }

                return $this->writer->fill(
                    $this->messages->make($game, 'SongInfoResponse\\GroupData'),
                    [
                        'setSongNo' => $songNo,
                        'setAryHighScore' => $scores,
                        'setAryFriendScore' => [],
                    ],
                );
            })
            ->all();

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'SongInfoResponse'), [
                'setResult' => 1,
                'setAryGroupScore' => $groups,
            ])
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
                'setHashCrownFlg' => $this->scoreMapper->crownFlagBytes($bests, $game->value),
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
                'setSongHashTbl' => $this->songHashTable($game->value),
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
                // Katsu Don's DefaultsongResponse carries the flag bytes in
                // field 2 (default_song_flg) and has no song_hash_ver; newer
                // dialects lack this setter, so MessageWriter skips it for them.
                // The blob is a 64-byte bitset indexed directly by unique_id,
                // NOT by song position or the one-based song flag convention.
                'setDefaultSongFlg' => $this->defaultMusicFlg($game),
            ])
        );
    }

    public function balanceCheck(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'BalancecheckRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'BalancecheckResponse'), [
                'setResult' => 1,
                'setPersonid' => $message->getPersonid(),
                'setBnidResult' => 'Ok',
                'setCoinCoupon' => 9999,
            ])
        );
    }

    public function battleUserData(Request $request, TaikoGameVersion $game): Response
    {
        throw new \RuntimeException("Battle user data endpoint is not implemented for version {$game->value}");
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

        $rawRequested = collect($message->getGetDan())
            ->take(self::MAX_TAIKOJUKU_REQUEST_SLOTS)
            ->map(fn (mixed $dan): int => (int) $dan)
            ->values();

        $requested = $rawRequested
            ->filter(fn (int $dan): bool => $dan >= self::MIN_NORMAL_DAN && $dan <= self::MAX_NORMAL_DAN)
            ->unique()
            ->values();

        // Some clients send placeholder/out-of-range values while still
        // expecting one response pack per requested slot.
        if ($requested->isEmpty() && $rawRequested->isNotEmpty()) {
            $requested = collect(range(1, $rawRequested->count()));
        }

        $validSongNumbers = Song::query()
            ->where('version', $game->value)
            ->pluck('song_no')
            ->map(fn (mixed $dan): int => (int) $dan)
            ->flip();

        $courses = DanCourse::query()
            ->with('songs')
            ->where('version', $game->value)
            ->whereIn('dan', $requested->all())
            ->get()
            ->keyBy('dan');

        $fallbackSongs = Song::query()
            ->where('version', $game->value)
            ->orderBy('id')
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo)
            ->values();

        $packs = $requested
            ->map(function (int $dan, int $index) use ($courses, $fallbackSongs, $game, $validSongNumbers): ?Message {
                /** @var DanCourse|null $course */
                $course = $courses->get($dan);
                $verupNo = 0;

                if ($course instanceof DanCourse) {
                    $verupNo = (int) $course->verup_no;
                    $songs = $course->songs
                        ->filter(fn (DanCourseSong $song): bool => $validSongNumbers->has((int) $song->song_no))
                        ->filter(fn (DanCourseSong $song): bool => (int) $song->level >= self::MIN_COURSE_LEVEL
                            && (int) $song->level <= self::MAX_COURSE_LEVEL)
                        ->take(self::MAX_TAIKOJUKU_SONGS)
                        ->map(fn (DanCourseSong $song): array => [
                            'song_no' => (int) $song->song_no,
                            'level' => (int) $song->level,
                        ])
                        ->values();
                } else {
                    $songs = $fallbackSongs->skip($index * 3)->take(3);
                    if ($songs->isEmpty()) {
                        $songs = $fallbackSongs->take(3);
                    }
                    $level = min(self::MAX_COURSE_LEVEL, self::MIN_COURSE_LEVEL + $index);
                    $songs = $songs->map(fn (int $songNo): array => [
                        'song_no' => $songNo,
                        'level' => $level,
                    ]);
                }

                if ($songs->isEmpty()) {
                    return null;
                }

                return $this->writer->fill(
                    $this->messages->make($game, 'TaikojukuResponse\\JukupackData'),
                    [
                        'setGetDan' => $dan,
                        'setVerupNo' => $verupNo,
                        'setAryJukusongData' => $songs
                            ->map(fn (array $song): Message => $this->writer->fill(
                                $this->messages->make($game, 'TaikojukuResponse\\JukupackData\\JukusongData'),
                                [
                                    'setSongNo' => $song['song_no'],
                                    'setLevel' => $song['level'],
                                ],
                            ))
                            ->all(),
                    ],
                );
            })
            ->filter()
            ->values()
            ->all();

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'TaikojukuResponse'), [
                'setResult' => 1,
                'setAryJukupackData' => $packs,
            ])
        );
    }

    /**
     * @return array<int, array{dan: int, verup_no: int}>
     */
    protected function taikojukuInformationRows(TaikoGameVersion $game): array
    {
        return DanCourse::query()
            ->where('version', $game->value)
            ->whereBetween('dan', [self::MIN_NORMAL_DAN, self::MAX_NORMAL_DAN])
            ->whereHas('songs')
            ->orderBy('dan')
            ->get(['dan', 'verup_no'])
            ->map(fn (DanCourse $course): array => [
                'dan' => (int) $course->dan,
                'verup_no' => (int) $course->verup_no,
            ])
            ->all();
    }

    public function getGhostData(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'GetghostdataRequest');
        $baid = $message->getBaid();

        $ghostState = PlayerGreenGhostState::query()->where('baid', $baid)->first();
        $tokenStates = PlayerGreenGhostToken::query()->where('baid', $baid)->orderBy('token_id')->get();
        $winningsStates = PlayerGreenGhostWinnings::query()->where('baid', $baid)->orderBy('level_id')->get();

        $releaseInfoFlag = $ghostState?->release_info_flag;
        if (is_resource($releaseInfoFlag)) {
            $releaseInfoFlag = stream_get_contents($releaseInfoFlag);
        }
        $releaseInfoFlag = $releaseInfoFlag !== null ? str_pad($releaseInfoFlag, 16, "\x00") : $this->scoreMapper->emptyFlagBytes();

        $tokens = [];
        foreach ($tokenStates as $token) {
            $tokens[] = $this->writer->fill($this->messages->make($game, 'GetghostdataResponse\\GhostTokenData'), [
                'setTokenId' => (int) $token->token_id,
                'setTokenValue' => (int) $token->token_value,
            ]);
        }

        $winnings = [];
        foreach ($winningsStates as $win) {
            $winnings[] = $this->writer->fill($this->messages->make($game, 'GetghostdataResponse\\GhostRankData\\GhostWinningsData'), [
                'setLevelId' => (int) $win->level_id,
                'setWinnings' => (int) $win->winnings,
            ]);
        }

        $perfData = $this->writer->fill($this->messages->make($game, 'GetghostdataResponse\\GhostPerfData'), [
            'setInputMedian' => (int) ($ghostState?->input_median ?? 0),
            'setInputVariance' => (int) ($ghostState?->input_variance ?? 0),
        ]);

        $recordData = $this->writer->fill($this->messages->make($game, 'GetghostdataResponse\\GhostRankData'), [
            'setRankId' => (int) ($ghostState?->rank_id ?? 1),
            'setWinPoint' => (int) ($ghostState?->win_point ?? 0),
            'setCertifiedLevelId' => (int) ($ghostState?->certified_level_id ?? 0),
            'setAryWinningsData' => $winnings,
        ]);

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'GetghostdataResponse'), [
                'setResult' => 1,
                'setReleaseInfoFlag' => $releaseInfoFlag,
                'setPlayedSongFlag' => $this->ghostPlayedSongFlag($game->value),
                'setTotalWinnings' => (int) ($ghostState?->total_winnings ?? 0),
                'setGhostPerfData' => $perfData,
                'setGhostRecordData' => $recordData,
                'setAryTokenData' => $tokens,
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

        $songNos = Song::query()
            ->where('version', $game->value)
            ->where('song_no', '>', 0)
            ->where('song_no', '<', 1024)
            ->pluck('song_no')
            ->map(fn (mixed $no): int => (int) $no)
            ->shuffle();

        $bestSongLimit = in_array($game, [
            TaikoGameVersion::Sorairo,
            TaikoGameVersion::Momoiro,
            TaikoGameVersion::Kimidori,
        ], true) ? 5 : 10;

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'RecommendResponse'), [
                'setResult' => 1,
                'setRecommendSong' => $songNos->first() ?? 0,
                'setRecommendBestSong' => $songNos->take($bestSongLimit)->values()->all(),
            ])
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
        $message = $this->parse($request, $game, 'RewardcardcheckRequest');

        // Resolve the baid linked to the scanned reward card so the cabinet can
        // associate the reward with the player. Unknown cards return baid 0,
        // matching TaikoLocalServer's "card?.Baid ?? 0".
        $card = GameCard::query()->find($message->getAccessCode());

        $response = $this->messages->make($game, 'RewardcardcheckResponse');
        $this->writer->set($response, 'setResult', 1);
        $this->writer->set($response, 'setBaid', $card instanceof GameCard ? (int) $card->baid : 0);

        return $this->payloads->response($response);
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
                'played_at' => now(),
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

    /**
     * Default-song enable bitset for Katsu Don. Its defmusic archive contains
     * a 64-byte bitset keyed directly by musicinfo unique_id.
     */
    protected function defaultMusicFlg(TaikoGameVersion $game): string
    {
        if ($game !== TaikoGameVersion::Katsudon) {
            return $this->releaseSongFlag($game->value);
        }

        $uniqueIds = Song::query()
            ->where('version', $game->value)
            ->pluck('unique_id')
            ->map(fn (mixed $uniqueId): int => (int) $uniqueId);

        return $this->scoreMapper->katsudonDefaultSongFlagBytes($uniqueIds);
    }

    protected function releaseSongFlag(string $gameVersion, ?array $activeSeason = null): string
    {
        $songNumbers = Song::query()
            ->where('version', $gameVersion)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo)
            ->filter(function (int $songNo) use ($activeSeason): bool {
                if ($activeSeason) {
                    $isShopSong = false;
                    foreach ($activeSeason['items'] as $item) {
                        if ($item['item_type'] === 1 && $item['item_id'] === $songNo) {
                            $isShopSong = true;
                            break;
                        }
                    }
                    if ($isShopSong) {
                        return false;
                    }
                }

                return true;
            })
            ->values()
            ->all();

        return $this->scoreMapper->releaseSongFlagBytes($gameVersion, $songNumbers);
    }

    /**
     * Legacy dialects expect their full song list here; newer versions ignore
     * the table and resolve songs through the song_no-indexed flags instead.
     */
    protected function songHashTable(string $gameVersion): string
    {
        if (! $this->scoreMapper->isLegacySongList($gameVersion)) {
            return '';
        }

        $songNumbers = Song::query()
            ->where('version', $gameVersion)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo);

        return $this->scoreMapper->legacySongHashTable($songNumbers);
    }

    protected function shoppingResultResponse(TaikoGameVersion $game, ?Player $player = null): Message
    {
        $donPointState = $player instanceof Player
            ? PlayerDonPointState::resolve($player->baid, $game)
            : null;
        $cosmetic = $player instanceof Player
            ? PlayerCosmetic::resolve($player->baid, $game)
            : null;

        return $this->writer->fill($this->messages->make($game, 'ShoppingResultResponse'), [
            'setResult' => 1,
            'setTotalGetDonpoint' => (int) ($donPointState?->total_get_donpoint ?? 0),
            'setTotalUseDonpoint' => (int) ($donPointState?->total_use_donpoint ?? 0),
            'setToneFlg' => $this->scoreMapper->idFlagBytes($cosmetic?->unlocked_tones ?? [], 16),
            'setCostumeFlg1' => $this->scoreMapper->idFlagBytes(($cosmetic?->unlocked_costumes ?? [])['1'] ?? [], 32),
            'setCostumeFlg2' => $this->scoreMapper->idFlagBytes(($cosmetic?->unlocked_costumes ?? [])['2'] ?? [], 32),
            'setCostumeFlg3' => $this->scoreMapper->idFlagBytes(($cosmetic?->unlocked_costumes ?? [])['3'] ?? [], 32),
            'setSongHashVer' => 99,
            'setHashReleaseSongFlg' => $this->releaseSongFlag($game->value),
        ]);
    }

    /**
     * @param  array<int, int>  $existing
     * @param  iterable<int>  $incoming
     * @return array<int, int>
     */
    protected function mergeIds(array $existing, iterable $incoming): array
    {
        return collect($existing)
            ->merge(collect($incoming)->map(fn (mixed $id): int => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function getItemShopInfo(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'GetitemshopinfoRequest');
        $catalog = new ItemShopCatalog($game);
        $activeSeason = $catalog->getActiveSeason();

        if (! $catalog->isEnabled || ! $activeSeason) {
            return $this->payloads->response(
                $this->writer->set($this->messages->make($game, 'GetitemshopinfoResponse'), 'setResult', 1)
            );
        }

        $items = [];
        foreach ($activeSeason['items'] as $item) {
            $items[] = $this->writer->fill($this->messages->make($game, 'GetitemshopinfoResponse\\ItemshopData'), [
                'setItemNo' => $item['item_no'],
                'setItemType' => $item['item_type'],
                'setItemId' => $item['item_id'],
                'setItemPrice' => $item['item_price'],
            ]);
        }

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'GetitemshopinfoResponse'), [
                'setResult' => 1,
                'setVerupNo' => (int) $activeSeason['verup_no'],
                'setSeasonId' => (int) $activeSeason['season_id'],
                'setTelop' => $activeSeason['telop'],
                'setStartDatetime' => $activeSeason['start_datetime'],
                'setEndDatetime' => $activeSeason['end_datetime'],
                'setAfterstartDays' => (int) $activeSeason['afterstart_days'],
                'setBeforecloseDays' => (int) $activeSeason['beforeclose_days'],
                'setAryItemshopData' => $items,
            ])
        );
    }

    public function itemPurchase(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'ItempurchaseRequest');
        $player = Player::query()->find($message->getBaid());
        if (! $player instanceof Player) {
            return $this->payloads->response(
                $this->writer->set($this->messages->make($game, 'ItempurchaseResponse'), 'setResult', 0)
            );
        }

        $catalog = new ItemShopCatalog($game);
        $activeSeason = $catalog->getActiveSeason();

        $itemNo = $message->getItemNo();

        // 1. Preflight check: item_no == 0
        if ($itemNo === 0) {
            $totalGet = 0;
            $totalUse = 0;
            if ($catalog->isEnabled && $activeSeason) {
                $seasonState = PlayerShopSeasonState::query()->firstOrCreate([
                    'baid' => $player->baid,
                    'game_version' => $game->value,
                    'season_id' => $activeSeason['season_id'],
                ]);
                $totalGet = $seasonState->total_get_donmedal;
                $totalUse = $seasonState->total_use_donmedal;
            }

            return $this->payloads->response(
                $this->writer->fill($this->messages->make($game, 'ItempurchaseResponse'), [
                    'setResult' => 1,
                    'setTotalGetDonmedal' => $totalGet,
                    'setTotalUseDonmedal' => $totalUse,
                ])
            );
        }

        // 2. Normal purchase
        if (! $catalog->isEnabled || ! $activeSeason) {
            return $this->payloads->response(
                $this->writer->set($this->messages->make($game, 'ItempurchaseResponse'), 'setResult', 0)
            );
        }

        // Validate that requested item matches catalog
        $itemType = method_exists($message, 'hasItemType') && ! $message->hasItemType() ? null : $message->getItemType();
        $itemId = method_exists($message, 'hasItemId') && ! $message->hasItemId() ? null : $message->getItemId();
        $itemPrice = method_exists($message, 'hasItemPrice') && ! $message->hasItemPrice() ? null : $message->getItemPrice();

        $catalogItem = null;
        foreach ($activeSeason['items'] as $item) {
            if ($item['item_no'] === $itemNo) {
                $catalogItem = $item;
                break;
            }
        }

        if ($catalogItem === null) {
            return $this->payloads->response(
                $this->writer->set($this->messages->make($game, 'ItempurchaseResponse'), 'setResult', 0)
            );
        }

        // Rejects forged catalog tuple
        if (($itemType !== null && $catalogItem['item_type'] !== $itemType) ||
            ($itemId !== null && $catalogItem['item_id'] !== $itemId) ||
            ($itemPrice !== null && $catalogItem['item_price'] !== $itemPrice)) {
            return $this->payloads->response(
                $this->writer->set($this->messages->make($game, 'ItempurchaseResponse'), 'setResult', 0)
            );
        }

        // Rejects zero price rows
        if ($catalogItem['item_price'] === 0) {
            return $this->payloads->response(
                $this->writer->set($this->messages->make($game, 'ItempurchaseResponse'), 'setResult', 0)
            );
        }

        return DB::transaction(function () use ($player, $game, $activeSeason, $catalogItem): Response {
            $seasonState = PlayerShopSeasonState::query()->lockForUpdate()->firstOrCreate([
                'baid' => $player->baid,
                'game_version' => $game->value,
                'season_id' => $activeSeason['season_id'],
            ]);

            $available = $seasonState->total_get_donmedal - $seasonState->total_use_donmedal;
            if ($available < $catalogItem['item_price']) {
                return $this->payloads->response(
                    $this->writer->set($this->messages->make($game, 'ItempurchaseResponse'), 'setResult', 0)
                );
            }

            // Rejects duplicate unlocked
            $exists = PlayerShopItem::query()
                ->where('baid', $player->baid)
                ->where('game_version', $game->value)
                ->where('season_id', $activeSeason['season_id'])
                ->where('item_type', $catalogItem['item_type'])
                ->where('item_id', $catalogItem['item_id'])
                ->exists();

            if ($exists) {
                return $this->payloads->response(
                    $this->writer->set($this->messages->make($game, 'ItempurchaseResponse'), 'setResult', 0)
                );
            }

            // Spend medals
            $seasonState->total_use_donmedal += $catalogItem['item_price'];
            $seasonState->save();

            // Persist item purchase
            PlayerShopItem::query()->create([
                'baid' => $player->baid,
                'game_version' => $game->value,
                'season_id' => $activeSeason['season_id'],
                'item_type' => $catalogItem['item_type'],
                'item_id' => $catalogItem['item_id'],
                'item_no' => $catalogItem['item_no'],
                'item_price' => $catalogItem['item_price'],
            ]);

            // Unlock item immediately
            if ($catalogItem['item_type'] === 1) {
                // Song
                $player->unlocked_song_numbers = array_merge(
                    $player->unlocked_song_numbers ?? [],
                    [$catalogItem['item_id']]
                );
                $player->save();
            } else {
                $cosmetic = PlayerCosmetic::resolve($player->baid, $game);
                if ($catalogItem['item_type'] === 2) {
                    // Tone
                    $cosmetic->unlocked_tones = array_merge(
                        $cosmetic->unlocked_tones ?? [],
                        [$catalogItem['item_id']]
                    );
                } else {
                    // Costume slots: 3 (kigurumi) -> 1, 5 (head) -> 2, 4 (body) -> 3, 6 (face) -> 4, 7 (puchi) -> 5
                    $slot = match ($catalogItem['item_type']) {
                        3 => 1,
                        5 => 2,
                        4 => 3,
                        6 => 4,
                        7 => 5,
                        default => null,
                    };
                    if ($slot !== null) {
                        $costumes = $cosmetic->unlocked_costumes ?? [];
                        $costumes[(string) $slot] = array_merge(
                            $costumes[(string) $slot] ?? [],
                            [$catalogItem['item_id']]
                        );
                        $cosmetic->unlocked_costumes = $costumes;
                    }
                }
                $cosmetic->save();
            }

            return $this->payloads->response(
                $this->writer->fill($this->messages->make($game, 'ItempurchaseResponse'), [
                    'setResult' => 1,
                    'setTotalGetDonmedal' => $seasonState->total_get_donmedal,
                    'setTotalUseDonmedal' => $seasonState->total_use_donmedal,
                ])
            );
        });
    }

    public function getBanacoinInfo(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'GetbanacoininfoRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'GetbanacoininfoResponse'), 'setResult', 1)
        );
    }

    public function banacoinPayment(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'BanacoinpaymentRequest');

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'BanacoinpaymentResponse'), [
                'setResult' => 1,
                'setPersonid' => $message->getPersonid(),
                'setBnidResult' => 'Ok',
                'setChid' => '1',
            ])
        );
    }

    public function banacoinErrorLog(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'BanacoinerrorlogRequest');

        return $this->payloads->response(
            $this->writer->set($this->messages->make($game, 'BanacoinerrorlogResponse'), 'setResult', 1)
        );
    }
}
