<?php

namespace App\Models;

use App\Services\WaddamburoSongs;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A dan dojo course of one series. requirements is a list of {type, red, gold}: red and gold hold one value
 * for the whole course, or one per song. 0 = no such requirement at that level or for that song (Murasaki's
 * 六段 asks hits for red but score for gold). bads and oks must stay under their value, the rest reach it.
 * content_hash changes with the songs or the requirements, so a pass never carries over to a changed course.
 */
#[Fillable(['series', 'level', 'name', 'requirements', 'content_hash'])]
class WdbDanCourse extends Model
{
    /** The series in the order players see them, newest first: id => name. */
    /** The ladder's ranks in every series, in romaji; any other course is a challenge. */
    public const RANK_LABELS = [
        '初級' => 'Beginner',
        '十級' => '10 Kyu', '九級' => '9 Kyu', '八級' => '8 Kyu', '七級' => '7 Kyu', '六級' => '6 Kyu',
        '五級' => '5 Kyu', '四級' => '4 Kyu', '三級' => '3 Kyu', '二級' => '2 Kyu', '一級' => '1 Kyu',
        '４級' => '4 Kyu', '３級' => '3 Kyu', '２級' => '2 Kyu', '１級' => '1 Kyu',
        '初段' => '1 Dan', '二段' => '2 Dan', '三段' => '3 Dan', '四段' => '4 Dan', '五段' => '5 Dan',
        '六段' => '6 Dan', '七段' => '7 Dan', '八段' => '8 Dan', '九段' => '9 Dan', '十段' => '10 Dan',
        '玄人' => 'Kurouto', '名人' => 'Meijin', '超人' => 'Chojin', '達人' => 'Tatsujin',
        '先鋒' => 'Senpo', '次鋒' => 'Jiho', '中堅' => 'Chuken', '副将' => 'Fukusho', '大将' => 'Taisho',
    ];

    /** Green's nameplate art shared by all series; 0 means this is a challenge. */
    public function nameplateRank(): int
    {
        $name = match ($this->name) {
            '４級' => '四級', '３級' => '三級', '２級' => '二級', '１級' => '一級',
            '先鋒' => '初段', '次鋒' => '二段', '中堅' => '三段', '副将' => '四段', '大将' => '五段',
            default => $this->name,
        };
        $rank = array_search($name, [
            '初級', '十級', '九級', '八級', '七級', '六級', '五級', '四級', '三級', '二級', '一級',
            '初段', '二段', '三段', '四段', '五段', '六段', '七段', '八段', '九段', '十段',
            '玄人', '名人', '超人', '達人',
        ], true);

        return $rank === false ? 0 : $rank + 1;
    }

    /** The parts challenge names are made of, longest first, in English. */
    private const CHALLENGE_WORDS = [
        '太鼓チームからの挑戦！' => 'Taiko Team Challenge ',
        '第3回天下一音ゲ祭の巻' => '3rd Tenkaichi Otogesai',
        'チャレンジ！グリーン外伝' => 'Green Gaiden ',
        // The name spells 外伝 in pieces, as the course's own art does.
        'タトイ云-一ノ十' => 'Secret Gaiden ',
        'ドンダフルコンボ！！' => 'Donderful Combo',
        'ナムコオリジナル' => 'Namco Original ',
        '世界大会2016予選' => 'World Tournament 2016 Qualifier ',
        '世界大会2016エリア' => 'World Tournament 2016 Area ',
        '世界大会2016決勝' => 'World Tournament 2016 Final',
        '世界大会2016EX' => 'World Tournament 2016 EX ',
        '334万点！！' => '3.34 Million Points',
        '333万点！！' => '3.33 Million Points',
        '3500コンボ！！' => '3500 Combo',
        '1400連打！！' => '1400 Rolls',
        '【裏】' => ' (Ura)',
        '挑戦！' => '',
        '復活！' => 'Revival: ',
        'モモイロ' => 'Momoiro ', 'ソライロ' => 'Sorairo ', 'カツドン' => 'Katsu-Don ', 'キミドリ' => 'Kimidori ', 'ムラサキ' => 'Murasaki ',
        'ホワイト' => 'White ', 'レッド' => 'Red ', 'イエロー' => 'Yellow ', 'ブルー' => 'Blue ',
        '十段' => '10 Dan', '達人' => 'Tatsujin', '大将' => 'Taisho',
    ];

    /** A challenge course's name in English; digits and circled numbers come through as plain digits. */
    public static function challengeTitle(string $name): string
    {
        $plain = strtr(mb_convert_kana($name, 'rn'), ['①' => '1', '②' => '2', '③' => '3', '④' => '4', '⑤' => '5', '⑥' => '6', '－' => '-']);

        return trim(strtr($plain, self::CHALLENGE_WORDS));
    }

    public const SERIES = [
        'nijiiro-2026' => 'Nijiiro 2026',
        'nijiiro-2025' => 'Nijiiro 2025',
        'nijiiro-2024' => 'Nijiiro 2024',
        'nijiiro-2023' => 'Nijiiro 2023',
        'nijiiro-2022' => 'Nijiiro 2022',
        'nijiiro-2021' => 'Nijiiro 2021',
        'nijiiro-2020' => 'Nijiiro 2020',
        'green' => 'Green',
        'blue' => 'Blue',
        'yellow' => 'Yellow',
        'red' => 'Red',
        'white' => 'White',
        'murasaki' => 'Murasaki',
        'kimidori' => 'Kimidori',
        'momoiro' => 'Momoiro',
        'sorairo' => 'Sorairo',
        'katsudon' => 'Katsu-Don',
    ];

    /** The source of a series' charts, and the rules its courses play by (null: Green and before). */
    public static function isNijiiro(string $series): bool
    {
        return str_starts_with($series, 'nijiiro-');
    }

    /**
     * The series an admin stopped serving: the game does not get them and their plays are refused.
     *
     * @return list<string>
     */
    public static function disabledSeries(): array
    {
        return DB::table('wdb_dan_disabled_series')->pluck('series')->all();
    }

    /** The official charts of these songs from this series' source, by song key and course. */
    public static function officialCharts(Collection $songKeys, string $series): Collection
    {
        return DB::table('wdb_chart_song')
            ->join('wdb_songs', 'wdb_songs.id', '=', 'wdb_chart_song.wdb_song_id')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_chart_song.wdb_chart_id')
            ->where('wdb_chart_song.source', self::isNijiiro($series) ? 'Nijiiro' : 'Stock')
            ->where('wdb_songs.family', WaddamburoSongs::family('Stock'))
            ->whereIn('wdb_songs.song_key', $songKeys)
            ->where('wdb_charts.is_stock', true)
            ->whereNull('wdb_charts.legacy_at')
            ->get(['wdb_songs.song_key', 'wdb_charts.course', 'wdb_charts.sha256'])
            ->groupBy(fn (object $chart): string => $chart->song_key."\0".$chart->course);
    }

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
        ];
    }

    public function songs(): HasMany
    {
        return $this->hasMany(WdbDanCourseSong::class)->orderBy('position');
    }
}
