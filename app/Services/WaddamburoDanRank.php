<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\WdbDanCourse;
use App\Models\WdbDanPlay;
use Illuminate\Support\Collection;

class WaddamburoDanRank
{
    /** @return array{series: string, label: string, name: string, rank: int, gold: bool, perfect: bool}|null */
    public function selected(Player $player, ?PlayerCosmetic $cosmetic = null): ?array
    {
        $cosmetic ??= PlayerCosmetic::resolve($player->baid, PlayerCosmetic::WADDAMBURO);

        return $cosmetic->wdb_dan_series === null ? null : $this->highest($player, $cosmetic->wdb_dan_series);
    }

    /**
     * The highest current course cleared in this series, using only server-verified, visible results.
     *
     * @return array{series: string, label: string, name: string, rank: int, gold: bool, perfect: bool}|null
     */
    public function highest(Player $player, string $series): ?array
    {
        if (! array_key_exists($series, WdbDanCourse::SERIES) || in_array($series, WdbDanCourse::disabledSeries(), true)) {
            return null;
        }

        $courses = WdbDanCourse::query()->where('series', $series)->orderByDesc('level')->get(['series', 'name', 'content_hash']);
        $bests = WdbDanPlay::query()->where('baid', $player->baid)->whereIn('content_hash', $courses->pluck('content_hash'))
            ->whereNotNull('rescored_at')->whereNull('hidden_at')->groupBy('content_hash')
            ->selectRaw('content_hash, MAX(result_level) AS best, MAX(CASE WHEN '.WdbDanPlay::PERFECT_CLEAR_SQL.' THEN 1 ELSE 0 END) AS perfect')
            ->get()->keyBy('content_hash');

        return $this->fromCourses($courses, $bests);
    }

    /**
     * Resolve the equipped ranks for a page of users without one query per player.
     *
     * @param  iterable<int>  $userIds
     * @return array<int, array{series: string, dojo: string, label: string, name: string, rank: int, gold: bool, perfect: bool}>
     */
    public function forUsers(iterable $userIds): array
    {
        $players = Player::query()->whereIn('user_id', collect($userIds)->filter()->unique())
            ->get(['baid', 'user_id']);
        $selections = PlayerCosmetic::query()->whereIn('baid', $players->pluck('baid'))
            ->where('game_version', PlayerCosmetic::WADDAMBURO)->whereNotNull('wdb_dan_series')
            ->whereNotIn('wdb_dan_series', WdbDanCourse::disabledSeries())
            ->pluck('wdb_dan_series', 'baid');
        if ($selections->isEmpty()) {
            return [];
        }

        $courses = WdbDanCourse::query()->whereIn('series', $selections->values())
            ->orderByDesc('level')->get(['series', 'name', 'content_hash']);
        $bests = WdbDanPlay::query()->whereIn('baid', $selections->keys())
            ->whereIn('content_hash', $courses->pluck('content_hash'))
            ->whereNotNull('rescored_at')->whereNull('hidden_at')
            ->groupBy('baid', 'content_hash')
            ->selectRaw('baid, content_hash, MAX(result_level) AS best, MAX(CASE WHEN '.WdbDanPlay::PERFECT_CLEAR_SQL.' THEN 1 ELSE 0 END) AS perfect')
            ->get()->groupBy('baid');
        $bySeries = $courses->groupBy('series');
        $ranks = [];
        foreach ($players as $player) {
            $series = $selections->get($player->baid);
            if ($series === null || ! array_key_exists($series, WdbDanCourse::SERIES)) {
                continue;
            }
            $rank = $this->fromCourses($bySeries->get($series, collect()),
                $bests->get($player->baid, collect())->keyBy('content_hash'));
            if ($rank !== null) {
                $ranks[$player->user_id] = [...$rank, 'dojo' => WdbDanCourse::SERIES[$series]];
            }
        }

        return $ranks;
    }

    /**
     * @param  Collection<int, WdbDanCourse>  $courses
     * @param  Collection<string, WdbDanPlay>  $bests
     * @return array{series: string, label: string, name: string, rank: int, gold: bool, perfect: bool}|null
     */
    private function fromCourses(Collection $courses, Collection $bests): ?array
    {
        foreach ($courses as $course) {
            $rank = $course->nameplateRank();
            $result = $bests->get($course->content_hash);
            $best = (int) ($result?->best ?? 0);
            if ($rank > 0 && $best >= WdbDanPlay::RED) {
                return [
                    'series' => $course->series,
                    'label' => WdbDanCourse::RANK_LABELS[$course->name],
                    'name' => $course->name,
                    'rank' => $rank,
                    'gold' => $best === WdbDanPlay::GOLD,
                    'perfect' => (bool) ($result?->perfect ?? false),
                ];
            }
        }

        return null;
    }
}
