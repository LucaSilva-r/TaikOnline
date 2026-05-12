<?php

namespace App\GameProtocol\Green\Services;

use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest;
use App\GameProtocol\Green\Proto\Taiko\PlayResultDataRequest\StageData;
use App\GameProtocol\Green\Proto\Taiko\SelfBestResponse;
use App\GameProtocol\Green\Proto\Taiko\SelfBestResponse\SelfBestData;
use App\GameProtocol\Green\Support\ScoreMapper;
use App\Models\Player;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Carbon\CarbonImmutable;

class PlayResultService
{
    public function __construct(private readonly ScoreMapper $scoreMapper) {}

    public function save(PlayResultDataRequest $data, string $gameVersion): int
    {
        $player = Player::query()->find($data->getBaid());
        if (! $player instanceof Player) {
            return 1;
        }

        $playedAt = $this->parsePlayedAt($data->getPlayDatetime());

        foreach ($data->getAryStageInfo() as $stage) {
            if (! $stage instanceof StageData) {
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
                'stage_mode' => $stage->getStageMode(),
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
                'selected_folder_id' => $stage->getSelectedFolderId(),
                'raw_stage' => [
                    'star_level' => $stage->getStarLevel(),
                    'support_level' => $stage->getSupportLevel(),
                    'is_favorite' => $stage->getIsFavorite(),
                    'is_recent' => $stage->getIsRecent(),
                ],
            ]);

            $this->updateBest($player, $stage, $rank, $gameVersion);
        }

        $player->update([
            'last_played_at' => $playedAt,
            'difficulty_played_course' => $data->getDifficultyPlayedCourse(),
            'difficulty_played_star' => $data->getDifficultyPlayedStar(),
            'recent_song_numbers' => $this->recentSongs($player, $data),
            'total_credit_count' => (int) $player->total_credit_count + 1,
        ]);

        return 1;
    }

    /**
     * @param  iterable<int>  $songNumbers
     */
    public function selfBest(Player $player, int $level, string $gameVersion, iterable $songNumbers): SelfBestResponse
    {
        $numbers = collect($songNumbers)->map(fn (mixed $value): int => (int) $value)->filter()->values();
        $query = SongBest::query()
            ->where('baid', $player->baid)
            ->where('game_version', $gameVersion);

        if ($level > 0) {
            $query->where('level', $level);
        }

        if ($numbers->isNotEmpty()) {
            $query->whereIn('song_no', $numbers);
        }

        $items = $query->get()
            ->map(fn (SongBest $best): SelfBestData => (new SelfBestData)
                ->setSongNo((int) $best->song_no)
                ->setSelfBestScore((int) $best->best_score)
                ->setUraBestScore(0))
            ->all();

        return (new SelfBestResponse)
            ->setResult(1)
            ->setLevel($level)
            ->setArySelfbestScore($items)
            ->setAryShinSelfbestScore([]);
    }

    private function updateBest(Player $player, StageData $stage, int $rank, string $gameVersion): void
    {
        $best = SongBest::query()->firstOrNew([
            'baid' => $player->baid,
            'game_version' => $gameVersion,
            'song_no' => $stage->getSongNo(),
            'level' => $stage->getLevel(),
        ]);

        if (! $best->exists || $stage->getPlayScore() >= (int) $best->best_score) {
            $best->fill([
                'best_score' => $stage->getPlayScore(),
                'best_score_rank' => $rank,
                'best_play_result' => $stage->getPlayResult(),
            ])->save();
        }
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
    private function recentSongs(Player $player, PlayResultDataRequest $data): array
    {
        $songs = collect($data->getAryStageInfo())
            ->filter(fn (mixed $stage): bool => $stage instanceof StageData)
            ->map(fn (StageData $stage): int => $stage->getSongNo());

        return $songs
            ->merge($player->recent_song_numbers ?? [])
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }
}
