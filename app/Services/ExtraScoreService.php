<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use App\Http\Middleware\EnsureZucchiniApiToken;
use App\Models\ExtraChart;
use App\Models\ExtraChartBest;
use App\Models\ExtraChartPlayResult;
use App\Models\Player;
use Carbon\CarbonInterface;
use Google\Protobuf\Internal\Message;
use Illuminate\Http\Request;
use JsonException;

class ExtraScoreService
{
    public const MAP_HEADER = 'X-TaikOnline-Extra-Map';

    /**
     * @return array<string, array{uid: int, level: int, sha256: string, title: string, source_id: string}>
     */
    public function associations(Request $request): array
    {
        if (! EnsureZucchiniApiToken::accepts($request)) {
            return [];
        }

        $encoded = (string) $request->header(self::MAP_HEADER, '');
        if ($encoded === '' || strlen($encoded) > 4096) {
            return [];
        }

        $padded = str_pad(strtr($encoded, '-_', '+/'), (int) ceil(strlen($encoded) / 4) * 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return [];
        }

        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || ! is_array($payload['charts'] ?? null)) {
            return [];
        }

        $associations = [];
        foreach (array_slice($payload['charts'], 0, 4) as $chart) {
            if (! is_array($chart)) {
                continue;
            }

            $uid = filter_var($chart['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $level = filter_var($chart['level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
            $sha256 = strtolower((string) ($chart['sha256'] ?? ''));
            if ($uid === false || $level === false || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                continue;
            }

            $key = $this->associationKey((int) $uid, (int) $level);
            if (isset($associations[$key])) {
                continue;
            }

            $associations[$key] = [
                'uid' => (int) $uid,
                'level' => (int) $level,
                'sha256' => $sha256,
                'title' => mb_substr(trim((string) ($chart['title'] ?? '')), 0, 255),
                'source_id' => mb_substr(trim((string) ($chart['source_id'] ?? '')), 0, 255),
            ];
        }

        return $associations;
    }

    public function associationKey(int $songNo, int $level): string
    {
        return $songNo.':'.$level;
    }

    /**
     * @param  array{uid: int, level: int, sha256: string, title: string, source_id: string}  $association
     */
    public function persistStage(
        Player $player,
        Message $data,
        Message $stage,
        int $stageIndex,
        TaikoGameVersion $version,
        string $sessionHash,
        CarbonInterface $playedAt,
        int $rank,
        array $association,
    ): void {
        $chart = ExtraChart::query()->firstOrNew(['sha256' => $association['sha256']]);
        if (! $chart->exists) {
            $chart->first_seen_at = $playedAt;
        }
        if (! $chart->observed_title && $association['title'] !== '') {
            $chart->observed_title = $association['title'];
        }
        if (! $chart->observed_source_id && $association['source_id'] !== '') {
            $chart->observed_source_id = $association['source_id'];
        }
        $chart->last_seen_at = $playedAt;
        $chart->save();

        ExtraChartPlayResult::query()->create([
            'baid' => $player->baid,
            'extra_chart_id' => $chart->id,
            'origin_game_version' => $version->value,
            'chassis_id' => $data->getChassisId(),
            'shop_id' => $data->getShopId(),
            'session_hash' => $sessionHash,
            'played_at' => $playedAt,
            'stage_index' => $stageIndex,
            'is_right' => $data->getIsRight(),
            'is_two_players' => $data->getIsTwoPlayers(),
            'runtime_song_no' => $stage->getSongNo(),
            'level' => $stage->getLevel(),
            'stage_mode' => $this->optionalInt($stage, 'getStageMode'),
            'play_result' => $stage->getPlayResult(),
            'score' => $stage->getPlayScore(),
            'score_rank' => $rank,
            'good_count' => $stage->getGoodCnt(),
            'ok_count' => $stage->getOkCnt(),
            'miss_count' => $stage->getNgCnt(),
            'drumroll_count' => $stage->getPoundCnt(),
            'combo_count' => $stage->getComboCnt(),
            'hit_count' => $this->stageHitCount($stage),
            'music_category' => $stage->getMusicCateg(),
            'selected_folder_id' => $this->optionalInt($stage, 'getSelectedFolderId'),
            'raw_stage' => [
                'star_level' => method_exists($stage, 'getStarLevel') ? $stage->getStarLevel() : null,
                'support_level' => method_exists($stage, 'getSupportLevel') ? $stage->getSupportLevel() : null,
            ],
        ]);

        $best = ExtraChartBest::query()->firstOrNew([
            'baid' => $player->baid,
            'extra_chart_id' => $chart->id,
            'is_shin' => $this->isShinStage($stage),
        ]);
        $dirty = ! $best->exists;
        if (! $best->exists || $stage->getPlayScore() >= (int) $best->best_score) {
            $best->fill([
                'best_score' => $stage->getPlayScore(),
                'best_score_rank' => $rank,
                'best_play_result' => $stage->getPlayResult(),
            ]);
            $dirty = true;
        }

        $crown = $this->crownForCounts((int) $stage->getOkCnt(), (int) $stage->getNgCnt());
        if ($crown > (int) $best->best_crown) {
            $best->best_crown = $crown;
            $dirty = true;
        }
        if ($dirty) {
            $best->save();
        }
    }

    private function optionalInt(Message $message, string $getter): int
    {
        return method_exists($message, $getter) ? (int) $message->{$getter}() : 0;
    }

    private function stageHitCount(Message $stage): int
    {
        return method_exists($stage, 'getHitCnt')
            ? (int) $stage->getHitCnt()
            : (int) $stage->getGoodCnt() + (int) $stage->getOkCnt() + (int) $stage->getNgCnt();
    }

    private function isShinStage(Message $stage): bool
    {
        return in_array($this->optionalInt($stage, 'getStageMode'), [1, 4], true);
    }

    private function crownForCounts(int $okCount, int $missCount): int
    {
        if ($missCount === 0 && $okCount === 0) {
            return 3;
        }

        return $missCount === 0 ? 2 : 1;
    }
}
