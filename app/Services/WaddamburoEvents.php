<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\WdbMedalSong;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Green's collab campaigns as Waddamburo events (Donder Hiroba's "チケットで交換"; the wiki's ぷちキャラ page names each
 * puchi's campaign). They come round in a fixed rotation, two weeks each, for ever. While one runs, every card play of
 * one of its songs (a medal song: normal play or AI battle) earns one ticket; its tickets buy its puchi at fixed prices,
 * on the website, while it runs. A campaign's tickets carry over to its next run; what was bought stays. A few puchi
 * came from playing one song during their campaign: they are owned by playing it while that campaign runs.
 * Green's gacha and raffle puchi (765, Love Live; 霊夢(青), LindaAI-CUE) are sold like the rest (user decision,
 * 2026-11-24). The 2016 world tournament's puchi (66, 67) are Katsu Shop goods, so that campaign is not here.
 */
class WaddamburoEvents
{
    /** The season_id event purchases carry in player_shop_items (the cabinet's are 1-4, the Katsu Shop's 99). */
    public const SEASON = 98;

    public const PUCHI = 7;

    /** The rotation's first day (a Monday), in the app's timezone; each campaign runs this many days. */
    public const EPOCH = '2026-11-23';

    public const RUN_DAYS = 14;

    public const PRICE = 5;

    /** Green's raffle prizes (チケットで抽選): dearer. */
    public const RAFFLE_PRICE = 10;

    private const RAFFLES = [90, 77];

    private const IMAS_765 = ['7iro', 'imas2t', 'imasop', 'imshhb', 'imsims', 'imsjbr', 'imsmah', 'imsmjd', 'imsmpr', 'imsmt5',
        'imsmtp', 'imsomn', 'imsshn', 'kirame', 'kyunva', 'miki', 'smokyt'];

    /**
     * The rotation, in order: key, name, songs (musicids, Green tags, a genre), puchi sold, puchi owned by playing a song.
     * Songs are the campaign's franchise as Green has it (subtitles: 「マクロスΔ」より ...); Macross Δ's songs left Green,
     * so its event takes the anime genre (ponytail: no better stand-in known).
     *
     * @var list<array{key: string, name: string, songs: list<string>, tags?: list<int>, genre?: string, puchi: list<int>, plays?: array<int, string>}>
     */
    public const CAMPAIGNS = [
        ['key' => 'touhou', 'name' => '東方Project×太鼓の達人 コラボキャンペーン', 'songs' => [], 'tags' => [66, 68],
            'puchi' => [5, 6, 7, 8, 9, 10, 11, 68, 86, 87, 88, 89, 90, 96, 97, 98, 99, 100, 101, 107, 117, 118, 119, 120]],
        ['key' => 'imas', 'name' => 'アイドルマスター×太鼓の達人 コラボ祭', 'songs' => [...self::IMAS_765, 'imm39', 'immbra'],
            'puchi' => [12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27]],
        ['key' => 'pad', 'name' => 'パズル＆ドラゴンズ×太鼓の達人 コラボキャンペーン', 'songs' => ['puzdr6', 'puzdr7', 'puzdra', 'puzdx1'],
            'puchi' => [78, 79, 80, 81, 82, 83, 84, 85]],
        ['key' => 'cinderella', 'name' => 'アイドルマスター シンデレラガールズ コラボキャンペーン', 'songs' => ['imc2bm', 'imc32b',
            'imc5in', 'imcagd', 'imcanz', 'imclip', 'imcnev', 'imconc', 'imcshn', 'imcsml', 'imctok'],
            'puchi' => [1, 2, 3, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 91, 92, 93, 94, 95]],
        ['key' => 'crossbeats', 'name' => 'クロスビーツレヴ コラボ', 'songs' => ['ov969', 'skrexh'], 'puchi' => [],
            'plays' => [74 => 'ov969', 75 => 'skrexh']],
        ['key' => 'lovelive', 'name' => 'ラブライブ！×太鼓の達人 コラボキャンペーン', 'songs' => ['llang', 'llbkrh', 'llsore'],
            'puchi' => [40, 41, 42, 43, 44, 45, 46, 47, 48]],
        ['key' => 'compass', 'name' => '＃コンパス×太鼓の達人 コラボキャンペーン', 'songs' => ['gumizk', 'mikuar', 'mikucp', 'mikudd',
            'mikugv', 'mikuhn', 'mikuvt', 'mikuzs'], 'puchi' => [113]],
        ['key' => 'macross', 'name' => 'マクロスΔ×太鼓の達人 コラボキャンペーン', 'songs' => [], 'genre' => 'アニメ',
            'puchi' => [69, 70, 71, 72, 73]],
        ['key' => 'sidem', 'name' => 'アイドルマスター SideM コラボキャンペーン', 'songs' => ['imedrv', 'imeglo', 'imersn'], 'puchi' => [],
            'plays' => [39 => 'imedrv']],
        ['key' => 'a3', 'name' => 'A3!×太鼓の達人 コラボキャンペーン', 'songs' => ['a3aaa', 'a3mank'], 'puchi' => [102, 103, 104, 105]],
        ['key' => 'ldh', 'name' => 'EXILE 15周年 タイアップキャンペーン', 'songs' => ['gomkis', 'ryusei', 'yeswea'], 'puchi' => [64, 65]],
        ['key' => 'shiny', 'name' => 'アイドルマスター シャイニーカラーズ コラボキャンペーン', 'songs' => ['im4dst', 'im4spr'],
            'puchi' => [114, 115, 116]],
        ['key' => 'docodon', 'name' => '太鼓の達人 ドコドン！ミステリーアドベンチャーコラボ', 'songs' => ['3d3op'], 'puchi' => [76, 77]],
    ];

    /** The puchi's names (the wiki's ぷちキャラ list): Green's data has only their name textures. */
    public const NAMES = [
        1 => '島村卯月', 2 => '渋谷凛', 3 => '本田未央', 5 => '博麗霊夢', 6 => '霧雨魔理沙', 7 => '十六夜咲夜', 8 => 'アリス・マーガトロイド',
        9 => 'レミリア・スカーレット', 10 => 'チルノ', 11 => '堀川雷鼓', 12 => '天海春香', 13 => '如月千早', 14 => '萩原雪歩', 15 => '高槻やよい',
        16 => '秋月律子', 17 => '水瀬伊織', 18 => '三浦あずさ', 19 => '双海亜美', 20 => '双海真美', 21 => '菊池真', 22 => '星井美希',
        23 => '我那覇響', 24 => '四条貴音', 25 => '春日未来', 26 => '最上静香', 27 => '伊吹翼', 28 => '城ヶ崎莉嘉', 29 => '赤城みりあ',
        30 => '諸星きらり', 31 => '双葉杏', 32 => '緒方智絵里', 33 => '三村かなこ', 34 => '新田美波', 35 => 'アナスタシア', 36 => '神崎蘭子',
        37 => '前川みく', 38 => '多田李衣菜', 39 => '山村賢', 40 => '高坂穂乃果', 41 => '絢瀬絵里', 42 => '南ことり', 43 => '園田海未',
        44 => '星空凛', 45 => '西木野真姫', 46 => '東條希', 47 => '小泉花陽', 48 => '矢澤にこ', 64 => 'MAKIDAI', 65 => '関口メンディー',
        68 => 'フランドール・スカーレット', 69 => 'フレイア・ヴィオン', 70 => '美雲・ギンヌメール', 71 => 'カナメ・バッカニア', 72 => 'マキナ・中島',
        73 => 'レイナ・プラウラー', 74 => '暁月', 75 => '燦', 76 => 'ティア＆ポポカカ', 77 => 'LindaAI-CUE', 78 => 'ゼウス', 79 => 'ヘラ',
        80 => '戦乙女・プリンセスヴァルキリー', 81 => '赤龍喚士・ソニア', 82 => '堕天使・ルシファー', 83 => '麒麟の化身・サクヤ', 84 => '威女神・カーリー',
        85 => 'タマゾーXどんちゃん', 86 => '藤原妹紅', 87 => '古明地さとり', 88 => '古明地こいし', 89 => '宇佐見蓮子&マエリベリー・ハーン', 90 => '博麗霊夢(青)',
        91 => '一ノ瀬志希', 92 => '塩見周子', 93 => '宮本フレデリカ', 94 => '城ヶ崎美嘉', 95 => '速水奏', 96 => '魂魄妖夢', 97 => '射命丸文',
        98 => '東風谷早苗', 99 => '鈴仙・優曇華院・イナバ', 100 => '秦こころ', 101 => '日焼けしたチルノ', 102 => '佐久間咲也', 103 => '皇天馬',
        104 => '摂津万里', 105 => '月岡紬', 107 => '博麗霊夢&霧雨魔理沙', 113 => 'リリカ', 114 => '櫻木真乃', 115 => '風野灯織', 116 => '八宮めぐる',
        117 => '博麗霊夢(東方香霖堂Ver.)', 118 => 'レミリア・スカーレット&十六夜咲夜', 119 => '八雲紫', 120 => '洩矢諏訪子',
    ];

    public function __construct(private readonly WaddamburoTitles $titles) {}

    /** @return array{index: int, start: CarbonImmutable, end: CarbonImmutable} the campaign running at that time */
    public function at(?CarbonImmutable $time = null): array
    {
        $epoch = CarbonImmutable::parse(self::EPOCH, config('app.timezone'));
        $run = intdiv((int) max(0, $epoch->diffInDays($time ?? CarbonImmutable::now(), false)), self::RUN_DAYS);
        $start = $epoch->addDays($run * self::RUN_DAYS);

        return ['index' => $run % count(self::CAMPAIGNS), 'start' => $start, 'end' => $start->addDays(self::RUN_DAYS)];
    }

    /** @return array{key: string, name: string, songs: list<string>, puchi: list<int>, plays?: array<int, string>} */
    public function campaign(int $index): array
    {
        return self::CAMPAIGNS[$index];
    }

    /** The current campaign for the client and the website: key, name, end, its songs (musicids). */
    public function current(): array
    {
        $now = $this->at();
        $campaign = self::CAMPAIGNS[$now['index']];

        return ['key' => $campaign['key'], 'name' => $campaign['name'], 'starts_at' => $now['start']->toIso8601String(),
            'ends_at' => $now['end']->toIso8601String(), 'music_ids' => $this->songs($now['index'])];
    }

    /**
     * A campaign's songs Green has (musicids), sorted.
     *
     * @return list<string>
     */
    public function songs(int $index): array
    {
        $campaign = self::CAMPAIGNS[$index];
        $songs = [];
        foreach ($this->titles->musicInfo() as $musicId => $info) {
            if (in_array($musicId, $campaign['songs'], true)
                || array_intersect($campaign['tags'] ?? [], $info['tags']) !== []
                || ($campaign['genre'] ?? null) === $info['genre']) {
                $songs[] = (string) $musicId;
            }
        }
        sort($songs);

        return $songs;
    }

    public function price(int $id): int
    {
        return in_array($id, self::RAFFLES, true) ? self::RAFFLE_PRICE : self::PRICE;
    }

    /**
     * The player's card plays of the campaign's songs while it ran: the songs' musicids, one entry a play.
     *
     * @return list<string>
     */
    public function plays(int $baid, int $index): array
    {
        $epoch = CarbonImmutable::parse(self::EPOCH, config('app.timezone'));
        $songs = WdbMedalSong::query()->where('baid', $baid)->where('kind', 'song')->whereNotNull('chart_sha256')
            ->where('played_at', '>=', $epoch)->get(['chart_sha256', 'played_at'])
            ->filter(fn (WdbMedalSong $song): bool => $this->at(CarbonImmutable::parse($song->played_at))['index'] === $index);
        $info = $this->titles->songInfo($songs->pluck('chart_sha256')->unique()->values()->all());
        $targets = array_flip($this->songs($index));

        return $songs->map(fn (WdbMedalSong $song): ?string => $info[$song->chart_sha256]['music_id'] ?? null)
            ->filter(fn (?string $musicId): bool => $musicId !== null && isset($targets[$musicId]))->values()->all();
    }

    /** Tickets left: one a play, less what its puchi cost. */
    public function tickets(int $baid, int $index): int
    {
        return count($this->plays($baid, $index)) - (int) PlayerShopItem::query()->where('baid', $baid)
            ->where('game_version', PlayerCosmetic::WADDAMBURO)->where('season_id', self::SEASON)
            ->where('item_type', self::PUCHI)->whereIn('item_id', self::CAMPAIGNS[$index]['puchi'])->sum('item_price');
    }

    /**
     * Every puchi the events give: [type, id] (they lock in Only what I earned).
     *
     * @return list<array{int, int}>
     */
    public function items(): array
    {
        $items = [];
        foreach (self::CAMPAIGNS as $campaign) {
            foreach ([...$campaign['puchi'], ...array_keys($campaign['plays'] ?? [])] as $id) {
                $items[] = [self::PUCHI, $id];
            }
        }

        return $items;
    }

    /**
     * The puchi the player has from events: bought, or owned by playing their song during their campaign.
     *
     * @return list<int>
     */
    public function owned(int $baid): array
    {
        $owned = PlayerShopItem::query()->where('baid', $baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
            ->where('season_id', self::SEASON)->where('item_type', self::PUCHI)->pluck('item_id')->map(fn ($id): int => (int) $id)->all();
        foreach (self::CAMPAIGNS as $index => $campaign) {
            if (($campaign['plays'] ?? []) === []) {
                continue;
            }
            $played = array_flip($this->plays($baid, $index));
            foreach ($campaign['plays'] as $id => $musicId) {
                if (isset($played[$musicId])) {
                    $owned[] = $id;
                }
            }
        }

        return array_values(array_unique($owned));
    }

    /** Buys a puchi of the running campaign: 'ok', 'owned', 'short' (not enough tickets) or 'unknown' (not sold now). */
    public function buy(Player $player, int $id): string
    {
        $index = $this->at()['index'];
        if (! in_array($id, self::CAMPAIGNS[$index]['puchi'], true)) {
            return 'unknown';
        }

        return DB::transaction(function () use ($player, $index, $id): string {
            Player::query()->lockForUpdate()->findOrFail($player->baid);
            if (in_array($id, $this->owned($player->baid), true)) {
                return 'owned';
            }
            $price = $this->price($id);
            if ($this->tickets($player->baid, $index) < $price) {
                return 'short';
            }
            PlayerShopItem::query()->create([
                'baid' => $player->baid,
                'game_version' => PlayerCosmetic::WADDAMBURO,
                'season_id' => self::SEASON,
                'item_type' => self::PUCHI,
                'item_id' => $id,
                'item_no' => 0,
                'item_price' => $price,
            ]);

            return 'ok';
        });
    }
}
