<?php

namespace App\GameProtocol\Green\Services;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Green\Support\MessageWriter;
use App\GameProtocol\Green\Support\ProtocolMessageResolver;
use App\GameProtocol\Green\Support\ScoreMapper;
use App\Models\Player;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Carbon\CarbonImmutable;
use Google\Protobuf\Internal\Message;

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

        $gameVersion = $version->value;
        $playedAt = $this->parsePlayedAt($data->getPlayDatetime());

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
                'ghost_sections' => $this->extractGhostSections($stage),
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
        if (! $stage->hasGhostStagedata() || ! $stage->getGhostStagedata() instanceof Message) {
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
}
