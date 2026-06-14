# Game Settings — Localization Strings

Source strings for the Game Settings screen (`resources/js/pages/settings/GameSettings.svelte`).
The UI currently ships English-only. This file preserves the original Japanese (from
donderhiroba.jp) so a future i18n pass can produce JA/EN locale catalogs.

The English text in this file is the literal string in the component today; the Japanese is the
authoritative donderhiroba label. Values in parentheses are the submitted form values, not display
text.

## Section headings

| English (current) | Japanese |
|---|---|
| Profile & Display Settings (Shared) | — |
| Default Play Options (Version Scoped) | ◆デフォルト演奏オプション |
| Default Tone (Version Scoped) | — |
| "Select by Difficulty" Folder Presets | ◆「むずかしさからえらぶ」フォルダ設定 |

## Field labels

| English (current) | Japanese |
|---|---|
| Prefecture | 都道府県 |
| Dan-i Display Setting | 段位表示設定 |
| Results Display Setting | 成績表示 |
| Public Profile | プロフィール公開設定 |
| Speed | はやさ |
| Vanish / Doron | ドロン |
| Reverse / Abekobe | あべこべ |
| Random | ランダム |
| Default Tone ID | 音色 |
| Folder Difficulty | むずかしさ |
| Stars / Rating | ★の数 |
| Display Order | 表示順 |

## Results Display Setting (`disp_score_type`)

| Value | English | Japanese |
|---|---|---|
| 0 | Oni + Ura Oni (Default) | おに＋おに裏 |
| 1 | Oni | おに |
| 2 | Hard | むずかしい |
| 3 | Normal | ふつう |
| 4 | Easy | かんたん |
| 5 | Do not display | 表示しない |

## Dan-i Display Setting (`disp_dan_type`)

| Value | English | Japanese |
|---|---|---|
| 0 | Do not display (Default) | 表示しない |
| 1 | Display | 表示する |

## Doron (`doron`)

| Value | English | Japanese |
|---|---|---|
| 0 | Do not vanish (Default) | しない |
| 1 | Vanish | する |

## Abekobe (`abekobe`)

| Value | English | Japanese |
|---|---|---|
| 0 | Do not reverse (Default) | しない |
| 1 | Reverse | する |

## Random (`random`)

| Value | English | Japanese |
|---|---|---|
| 0 | Do not randomize (Default) | しない |
| 1 | Whim | きまぐれ |
| 2 | Random | でたらめ |

## Default Tone (`default_tone_setting`)

Free-form numeric ID input today. donderhiroba presents a select; preserved for reference:

| Value | English | Japanese |
|---|---|---|
| 0 | Taiko (Default) | 太鼓 |
| 1 | Festival | お祭り |
| 2 | Dog & Cat | いぬねこ |
| 3 | Luxury Taiko | 豪華な太鼓 |
| 4 | Drum | ドラム |
| 5 | Tambourine | タンバリン |
| 13 | Rap | ラップ |
| 16 | Synth Drum | シンセドラム |

## Folder Difficulty (`difficulty_played_course`)

| Value | English | Japanese |
|---|---|---|
| 0 | Not set (Default) | 設定しない |
| 99 | Set during game | 都度ゲーム中に設定する |
| 1 | Easy | かんたん |
| 2 | Normal | ふつう |
| 3 | Hard | むずかしい |
| 4 | Oni | おに |
| 5 | Ura Oni | おに裏 |

## Stars / Rating (`difficulty_played_star`)

| Value | English | Japanese |
|---|---|---|
| 0 | Not set (Default) | 設定しない |
| 99 | Set during game | 都度ゲーム中に設定する |
| 1–10 | {n} Stars | n |

## Display Order (`difficulty_played_sort`)

| Value | English | Japanese |
|---|---|---|
| 0 | Not set (Default) | 設定しない |
| 99 | Set during game | 都度ゲーム中に設定する |
| 1 | As usual | いつもどおり |
| 2 | Uncleared first | 未クリア優先 |
| 3 | Non-full-combo first | 未フルコンボ優先 |
| 4 | Non-Donderful-combo first | 未ドンダフルコンボ優先 |

## Prefectures (`prefecture_id` / donderhiroba `area_id`)

IDs are the donderhiroba `area_id` values (not sequential JIS codes), shown in donderhiroba's
display order.

| ID | English | Japanese |
|---|---|---|
| 0 | Not set (Default) | 設定しない |
| 40 | Hokkaido | 北海道 |
| 2 | Aomori | 青森県 |
| 6 | Iwate | 岩手県 |
| 42 | Miyagi | 宮城県 |
| 3 | Akita | 秋田県 |
| 44 | Yamagata | 山形県 |
| 39 | Fukushima | 福島県 |
| 5 | Ibaraki | 茨城県 |
| 28 | Tochigi | 栃木県 |
| 18 | Gunma | 群馬県 |
| 20 | Saitama | 埼玉県 |
| 25 | Chiba | 千葉県 |
| 26 | Tokyo | 東京都 |
| 14 | Kanagawa | 神奈川県 |
| 34 | Niigata | 新潟県 |
| 30 | Toyama | 富山県 |
| 4 | Ishikawa | 石川県 |
| 37 | Fukui | 福井県 |
| 46 | Yamanashi | 山梨県 |
| 32 | Nagano | 長野県 |
| 15 | Gifu | 岐阜県 |
| 23 | Shizuoka | 静岡県 |
| 1 | Aichi | 愛知県 |
| 41 | Mie | 三重県 |
| 22 | Shiga | 滋賀県 |
| 16 | Kyoto | 京都府 |
| 9 | Osaka | 大阪府 |
| 35 | Hyogo | 兵庫県 |
| 33 | Nara | 奈良県 |
| 47 | Wakayama | 和歌山県 |
| 29 | Tottori | 鳥取県 |
| 24 | Shimane | 島根県 |
| 10 | Okayama | 岡山県 |
| 36 | Hiroshima | 広島県 |
| 45 | Yamaguchi | 山口県 |
| 27 | Tokushima | 徳島県 |
| 12 | Kagawa | 香川県 |
| 7 | Ehime | 愛媛県 |
| 19 | Kochi | 高知県 |
| 38 | Fukuoka | 福岡県 |
| 21 | Saga | 佐賀県 |
| 31 | Nagasaki | 長崎県 |
| 17 | Kumamoto | 熊本県 |
| 8 | Oita | 大分県 |
| 43 | Miyazaki | 宮崎県 |
| 13 | Kagoshima | 鹿児島県 |
| 11 | Okinawa | 沖縄県 |

## Not implemented (Nijiiro-era, unavailable on target generation)

These exist on current donderhiroba but are not supported on the targeted generation, so they are
omitted from the UI. Kept here for completeness.

| Field | English | Japanese |
|---|---|---|
| skip | Performance Skip | 演奏スキップ |
| notes_position | Note Position Adjustment | 音符位置調整 |
| voice | Don-chan Voice | ボイス |
| default_shin_setting | Double Play Chart Setting | 双打譜面表示設定 |

The Double Play (双打) chart setting is a Nijiiro-era feature and is not present on the targeted
generations, so it was removed entirely (UI, request, model, and DB column).

## Speed (`speed`)

The targeted generation only accepts four speeds. Stored as the low 3 bits of
`default_option_setting`.

| Value | English | Japanese |
|---|---|---|
| 0 | 1.0x (Default) | １．０倍 |
| 1 | 1.5x | １．５倍 |
| 2 | 2.0x | ２．０倍 |
| 3 | 3.0x | ３．０倍 |

## Version availability

Settings are gated by the generation that introduced them on donderhiroba:

| Setting | Available from |
|---|---|
| Enso options (speed/doron/abekobe/random) | Momoiro |
| Default tone | Murasaki |
| In-arcade ranking display difficulty (`disp_score_type`) | Murasaki |
