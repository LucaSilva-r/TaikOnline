<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Waddamburo's songs: a song is (family, song key), the key being the source's own song id (the
 * game's song id, an osu! beatmap set) sent by the client. Stock and Nijiiro are one family: they
 * share the game's song ids and most charts, so a song lists both, and each chart link records which
 * source shipped it. Charts without a key (TJA, uploads from before keys) are grouped by title and
 * subtitle under a "title:" key; once a chart arrives with a real key it leaves its title songs of
 * that family, and songs left without charts go.
 */
class WaddamburoSongs
{
    private const TITLE_KEY = 'title:';

    /** Sources sharing one song id space => their family. */
    private const FAMILIES = ['Stock' => 'Namco', 'Nijiiro' => 'Namco'];

    public static function family(string $source): string
    {
        return self::FAMILIES[$source] ?? $source;
    }

    /**
     * Links charts to their songs, creating or refreshing the songs. Each link: wdb_chart_id, source,
     * song_key (null = by title), title, subtitle, title_en, subtitle_en, osu_beatmapset_id. Display
     * metadata a link brings replaces the song's; what it lacks is kept (stock subtitles come from a
     * user-provided table, so one upload may have them and the next not).
     *
     * @param  list<array<string, mixed>>  $links
     */
    public static function attach(array $links): void
    {
        $songs = [];
        $pairs = [];
        foreach ($links as $link) {
            $source = self::text($link['source'] ?? null) ?? '';
            $family = self::family($source);
            // Stock writes Latin titles in full-width letters, Nijiiro in plain ones: one title for both.
            $plain = fn (?string $text): ?string => $family === 'Namco' && $text !== null ? self::halfWidth($text) : $text;
            $title = $plain(self::text($link['title'] ?? null));
            $subtitle = $plain(self::text($link['subtitle'] ?? null));
            $key = self::text($link['song_key'] ?? null);
            if ($key === null && $title === null) {
                continue;
            }
            $key ??= self::TITLE_KEY.$title."\n".$subtitle;
            $id = $family."\0".$key;
            $new = [
                'family' => $family, 'song_key' => $key, 'title' => $title, 'subtitle' => $subtitle,
                'title_en' => self::text($link['title_en'] ?? null), 'subtitle_en' => self::text($link['subtitle_en'] ?? null),
                'osu_beatmapset_id' => ($link['osu_beatmapset_id'] ?? null) ?: null,
            ];
            // Postgres refuses an upsert touching the same row twice: merge links to one song first.
            $songs[$id] = isset($songs[$id]) ? array_merge($songs[$id], array_filter($new, fn ($value) => $value !== null)) : $new;
            $pairs[] = [$id, (int) $link['wdb_chart_id'], $source];
        }
        if ($songs === []) {
            return;
        }

        $now = now();
        $refresh = fn (string $column) => DB::raw("COALESCE(excluded.{$column}, wdb_songs.{$column})");
        foreach (array_chunk($songs, 500, true) as $chunk) {
            DB::table('wdb_songs')->upsert(
                array_map(fn (array $song): array => [...$song, 'created_at' => $now, 'updated_at' => $now], array_values($chunk)),
                ['family', 'song_key'],
                ['title' => $refresh('title'), 'subtitle' => $refresh('subtitle'), 'title_en' => $refresh('title_en'),
                    'subtitle_en' => $refresh('subtitle_en'), 'osu_beatmapset_id' => $refresh('osu_beatmapset_id'),
                    'updated_at' => DB::raw('excluded.updated_at')]);
        }

        $ids = [];
        foreach (array_chunk($songs, 500, true) as $chunk) {
            DB::table('wdb_songs')
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $song) {
                        $query->orWhere(fn ($match) => $match->where('family', $song['family'])->where('song_key', $song['song_key']));
                    }
                })
                ->get(['id', 'family', 'song_key'])
                ->each(function (object $song) use (&$ids): void {
                    $ids[$song->family."\0".$song->song_key] = (int) $song->id;
                });
        }

        $rows = array_map(fn (array $pair): array => ['wdb_chart_id' => $pair[1], 'wdb_song_id' => $ids[$pair[0]], 'source' => $pair[2]], $pairs);
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('wdb_chart_song')->insertOrIgnore($chunk);
        }

        // A chart with its real song no longer belongs to the title songs of that family.
        $keyed = [];
        foreach ($pairs as [$id, $chartId]) {
            if (! str_starts_with($songs[$id]['song_key'], self::TITLE_KEY)) {
                $keyed[$songs[$id]['family']][] = $chartId;
            }
        }
        foreach ($keyed as $family => $chartIds) {
            foreach (array_chunk(array_unique($chartIds), 1000) as $chunk) {
                DB::table('wdb_chart_song')->whereIn('wdb_chart_id', $chunk)
                    ->whereIn('wdb_song_id', DB::table('wdb_songs')->select('id')
                        ->where('family', $family)->where('song_key', 'like', self::TITLE_KEY.'%'))
                    ->delete();
            }
        }
        DB::table('wdb_songs')->whereNotExists(fn ($query) => $query->from('wdb_chart_song')
            ->whereColumn('wdb_chart_song.wdb_song_id', 'wdb_songs.id'))->delete();
    }

    /** Full-width ASCII (Ｄｒｅａｄｎｏｕｇｈｔ) as plain ASCII. */
    private static function halfWidth(string $text): string
    {
        return preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn (array $char): string => chr(mb_ord($char[0]) - 0xFEE0), $text);
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : mb_substr($value, 0, 255);
    }
}
