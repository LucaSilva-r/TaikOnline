<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\WdbMedalSong;
use App\Models\WdbPlay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Green's condition titles, checked at the end of each credit as the cabinet does (research/ghidra/rewards-green.md):
 * the credit's songs against green_title_conditions.json (title -> condition, from the titles' names; lab
 * research/ghidra/scripts/green_title_conditions.py). Earned titles join the player's Waddamburo titles, so what they
 * give unlocks ("Only what I earned") and shows as new rewards. A song counts as Green's when its chart is a stock one;
 * cleared and full combo come from its verified play (failed songs are not uploaded: not cleared).
 */
class WaddamburoTitles
{
    /**
     * Checks a finished credit's songs and records the titles it earns.
     *
     * @param  list<WdbMedalSong>  $songs  the credit's songs, in order
     * @return list<int> the new title ids
     */
    public function checkCredit(Player $player, array $songs): array
    {
        $conditions = $this->conditions();
        if ($conditions === [] || $songs === []) {
            return [];
        }
        $info = $this->songInfo(array_values(array_filter(array_map(fn (WdbMedalSong $song): ?string => $song->chart_sha256, $songs))));
        $plays = WdbPlay::query()->whereKey(array_map(fn (WdbMedalSong $song): string => $song->id, $songs))->get()->keyBy('id');
        $credit = array_map(function (WdbMedalSong $song) use ($info, $plays): array {
            $play = $plays->get($song->id);
            $cleared = $play !== null && (bool) $play->cleared;

            return [
                'song' => $info[$song->chart_sha256 ?? ''] ?? null,
                'cleared' => $cleared,
                'fc' => $cleared && (int) $play->miss === 0,
                'ryo' => $cleared && (int) $play->miss === 0 && (int) $play->good === 0,
                'course' => $play?->course,
                'hits' => $play === null ? 0 : (int) $play->great + (int) $play->good,
                'options' => (int) ($play?->options ?? 0),
                'chart' => $song->chart_sha256,
                // A new personal best: no earlier play of the chart scored as much.
                'best' => $play !== null && ! WdbPlay::query()->where('baid', $play->baid)->where('wdb_chart_id', $play->wdb_chart_id)
                    ->where('id', '!=', $play->id)->where('played_at', '<', $play->played_at)->where('score', '>=', $play->score)->exists(),
                'side' => (int) $song->side,
                'seconds' => $song->select_seconds,
            ];
        }, $songs);

        $owned = $this->owned($player);
        $earned = [];
        foreach ($conditions as $condition) {
            $id = (int) $condition['title_id'];
            if (! in_array($id, $owned, true) && $this->met($condition, $credit, $player)) {
                $earned[] = $id;
            }
        }
        if ($earned !== []) {
            $row = PlayerCosmetic::query()->firstOrCreate(['baid' => $player->baid, 'game_version' => PlayerCosmetic::WADDAMBURO]);
            $row->unlocked_titles = array_values(array_unique([...array_map('intval', $row->unlocked_titles ?? []), ...$earned]));
            $row->save();
        }

        return $earned;
    }

    /** @param  list<array{song: ?array, cleared: bool, fc: bool, ryo: bool, best: bool, course: ?int, hits: int, options: int, chart: ?string, side: int, seconds: ?int}>  $credit */
    private function met(array $condition, array $credit, Player $player): bool
    {
        // A song of the condition's set: its genre, tag, title script or uniqueId.
        $in = function (?array $song) use ($condition): bool {
            if ($song === null) {
                return ! isset($condition['genre']) && ! isset($condition['tag']) && ! isset($condition['script']) && ! isset($condition['uids']);
            }

            return (! isset($condition['genre']) || $song['genre'] === $condition['genre'])
                && (! isset($condition['tag']) || in_array((int) $condition['tag'], $song['tags'], true))
                && (! isset($condition['script']) || ($song['tags'][0] ?? 0) === (int) $condition['script'])
                && (! isset($condition['uids']) || in_array($song['uid'], $condition['uids'], true));
        };
        // The player's name (Green's My Don name): any of the condition's spellings.
        if (isset($condition['name'])) {
            $names = array_map(fn (?string $name): string => mb_strtolower(trim((string) $name)),
                [$player->mydon_name, $player->user?->name, $player->user?->username]);
            if (array_intersect($names, array_map('mb_strtolower', $condition['name'])) === []) {
                return false;
            }
        }
        // How the song was played: its course, an option (WdbPlay::$options bits), the drum, where the pick stopped the timer.
        $how = fn (array $song): bool => (! isset($condition['course']) || $song['course'] === (int) $condition['course'])
            && (! isset($condition['max_course']) || ($song['course'] !== null && $song['course'] <= (int) $condition['max_course']))
            && (! isset($condition['option']) || match ($condition['option']) {
                'speed' => ($song['options'] >> 9) > 0 || ($song['options'] & 28) !== 0,
                // ばいそく / さんばい / よんばい: Green's x2 / x3 / x4 (Nijiiro's speed index 10 / 12 / 14, or the old bits).
                'speed2' => ($song['options'] >> 9) === 10 || ($song['options'] & 4) !== 0,
                'speed3' => ($song['options'] >> 9) === 12 || ($song['options'] & 8) !== 0,
                'speed4' => ($song['options'] >> 9) === 14 || ($song['options'] & 16) !== 0,
                'hidden' => ($song['options'] & 32) !== 0,
                'reversed' => ($song['options'] & 64) !== 0,
                'random' => ($song['options'] & 256) === 0 && ($song['options'] & 128) !== 0,
                'chaos' => ($song['options'] & 256) !== 0,
                default => false,
            })
            && (! isset($condition['side']) || $song['side'] === (int) $condition['side'])
            && (! isset($condition['seconds']) || in_array($song['seconds'], $condition['seconds'], true));
        $songUids = array_map(fn (array $song): ?int => $song['song']['uid'] ?? null, $credit);

        return match ($condition['kind']) {
            'credit' => true,
            // all_uids: each listed song is among them; fc: each one full combo.
            'clear_all' => collect($credit)->every(fn (array $song): bool => $song['cleared'] && (empty($condition['fc']) || $song['fc'])
                && (empty($condition['ryo']) || $song['ryo']) && (empty($condition['best']) || $song['best'])
                && $how($song) && $in($song['song']))
                && (empty($condition['all_uids']) || array_diff($condition['uids'], $songUids) === []),
            'select_all' => collect($credit)->every(fn (array $song): bool => $song['song'] !== null && $in($song['song'])),
            'play_any' => collect($credit)->contains(fn (array $song): bool => $song['song'] !== null && $how($song) && $in($song['song'])),
            'clear_any' => collect($credit)->contains(fn (array $song): bool => $song['song'] !== null && $song['cleared'] && $how($song) && $in($song['song'])),
            'fc_any' => collect($credit)->contains(fn (array $song): bool => $song['song'] !== null && $song['fc'] && $how($song) && $in($song['song'])),
            'hits' => collect($credit)->contains(fn (array $song): bool => $song['hits'] >= (int) $condition['count']),
            // The arcade's first-song revival: the first song failed and played again at once, that second try's result.
            'revival' => count($credit) >= 2 && ! $credit[0]['cleared'] && $credit[0]['chart'] !== null
                && $credit[1]['chart'] === $credit[0]['chart'] && $credit[1]['cleared'] === (bool) $condition['success'],
            'genre_plays' => $this->genrePlays($player, $condition['genre']) >= (int) $condition['count'],
            default => false,
        };
    }

    /** Every song the player finished in that genre, any credit (Donder Hiroba's play counts). */
    private function genrePlays(Player $player, string $genre): int
    {
        $charts = WdbMedalSong::query()->where('baid', $player->baid)->where('kind', 'song')->whereNotNull('chart_sha256')
            ->pluck('chart_sha256')->all();
        $info = $this->songInfo(array_values(array_unique($charts)));

        return count(array_filter($charts, fn (string $chart): bool => ($info[$chart]['genre'] ?? null) === $genre));
    }

    /** @return list<int> titles the player has: earned here or on a Green cabinet */
    public function owned(Player $player): array
    {
        return array_map('intval', $player->cosmetics()->whereIn('game_version', [PlayerCosmetic::WADDAMBURO, TaikoGameVersion::Green->value])
            ->pluck('unlocked_titles')->flatten()->filter()->all());
    }

    /**
     * Green's song facts for stock charts: chart sha256 -> uniqueId, genre, tags (musicinfo.xml).
     *
     * @param  list<string>  $charts
     * @return array<string, array{uid: int, genre: string, tags: list<int>}>
     */
    private function songInfo(array $charts): array
    {
        if ($charts === []) {
            return [];
        }
        $music = $this->musicInfo();
        $keys = DB::table('wdb_charts')->join('wdb_chart_song', 'wdb_chart_song.wdb_chart_id', '=', 'wdb_charts.id')
            ->join('wdb_songs', 'wdb_songs.id', '=', 'wdb_chart_song.wdb_song_id')
            ->whereIn('wdb_charts.sha256', $charts)->where('wdb_charts.source', 'Stock')->where('wdb_songs.family', 'Namco')
            ->pluck('wdb_songs.song_key', 'wdb_charts.sha256');
        $info = [];
        foreach ($keys as $sha => $musicId) {
            if (isset($music[$musicId])) {
                $info[$sha] = $music[$musicId];
            }
        }

        return $info;
    }

    /**
     * By musicid, from every Green revision's musicinfo: musicinfo.xml (the latest) first, then older ones
     * (musicinfo-*.xml) for the songs later revisions dropped. A song's facts are the same in every revision.
     *
     * @return array<string, array{uid: int, genre: string, tags: list<int>}>
     */
    private function musicInfo(): array
    {
        static $music = null;
        if ($music !== null) {
            return $music;
        }
        $dir = config('taiko_green.data_path').DIRECTORY_SEPARATOR.'green'.DIRECTORY_SEPARATOR;
        $music = [];
        foreach (array_merge(File::glob($dir.'musicinfo.xml'), File::glob($dir.'musicinfo-*.xml')) as $path) {
            foreach (simplexml_load_string(File::get($path))->MusicInfo->Data as $data) {
                $music[(string) $data->musicid] ??= [
                    'uid' => (int) $data->uniqueid,
                    'genre' => (string) $data->genrename,
                    'tags' => array_map('intval', iterator_to_array($data->tag, false)),
                ];
            }
        }

        return $music;
    }

    /** @return list<array<string, mixed>> */
    private function conditions(): array
    {
        static $conditions = null;
        if ($conditions !== null) {
            return $conditions;
        }
        $path = config('taiko_green.data_path').DIRECTORY_SEPARATOR.'green'.DIRECTORY_SEPARATOR.'green_title_conditions.json';

        return $conditions = File::exists($path) ? (json_decode(File::get($path), true)['titles'] ?? []) : [];
    }
}
