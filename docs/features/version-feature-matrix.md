# Gen-3 Per-Version Feature Availability Matrix

Audit of which Taiko no Tatsujin "new cabinet" (新筐体 / gen-3) features existed in each
version, sourced from the 譜面とかWiki update history
(`作品/新AC/アップデート履歴/{version}`). Goal: verify TaikOnline gates each feature to the
correct versions instead of assuming every feature is universal.

**Scope:** 初代 (無印, 2011) → グリーン (Green). The JP series order is:

| # | Wiki page | Enum case | Launch | ST id |
|---|---|---|---|---|
| 0 | 初代 (無印) | *(not modelled)* | 2011-11-16 | — |
| 1 | KATSU-DON | `Katsudon` | 2012-07-25 | ST2100-1 |
| 2 | ソライロ | `Sorairo` | 2013 | ST3100-1 |
| 3 | モモイロ | `Momoiro` | 2014 | ST4100-1 |
| 4 | キミドリ | `Kimidori` | 2015 | ST5100-1 |
| 5 | ムラサキ | `Murasaki` | 2015 | ST6100-1 |
| 6 | ホワイト | `White` | 2016 | ST7100-1 |
| 7 | レッド | `Red` | 2016 | ST8100-1 |
| 8 | イエロー | `Yellow` | 2017 | ST-9100-1 |
| 9 | ブルー | `Blue` | 2018 | ST-10100-1 |
| 10 | グリーン | `Green` | 2019 | ST-11100-1 |

> Note: TaikOnline's `TaikoGameVersion` enum starts at `Katsudon`; **初代 is not modelled at all.**
> Most "features" below are *donderhiroba* (the companion website, which TaikOnline reimplements)
> rather than in-cabinet behaviour. Website-side features are flagged **[WEB]**.

---

## Feature debut log (per version)

### 0. 初代 (無印) — launched 2011-11-16

Most feature-poor version. Launched bare; features trickled in via updates but the cabinet kept
the 無印 branding through GAME VERSION 1.x–8.x.

- **Titles (称号)** — NOT at launch. Added update3 (V3.13, 2012-04-17). Changeable on donderhiroba. **[WEB]**
- **Self-best notification (自己ベスト更新)** — added update3 (2012-04-17).
- **Tournament / challenge (大会・挑戦状)** — present; target songs flagged with icon (update3). **[WEB]**
- **Costumes (きせかえ)** — exist but **card-only**; on the 無印 cabinet they were forced/reset
  differently than later versions (V5.00 reset all progress to 3 costumes / 1 tone). More card
  costumes added later (V7.03: かぶとむし/いちご/うちゅうひこうし).
- **NOT present in 無印:** favourite folder, おすすめ (recommended) board, 最近あそんだ曲
  (recently-played) board, 段位道場 (dan dojo), best-score-in-select.
- donderhiroba in 無印 is minimal: title management + 大会・挑戦状 only.

### 1. KATSU-DON — launched 2012-07-25 (ST2100-1)

Large update introducing most of the "modern" gen-3 features.

- **donderhiroba redesign** — new layout. **[WEB]**
- **ドンチャレ (Don Challenge) page** — opened on donderhiroba (challenges, rewards, titles). **[WEB]**
- **Friends cap 30 → 50** **[WEB]**
- **Challenge/tournament upgrades [WEB]:** duration 1–10 days (was fixed 1 week); play options
  settable per challenge; tournaments up to 3 songs (was 1); entry conditions by title; up to 3
  official tournaments at once.
- **Twitter integration changed** — tweet window removed, replaced by "tweet button". **[WEB]**
- **Best-score-in-select** — added update8 (V2.09, 2012-09-27). **Requires a donderhiroba setting**
  (マイページ > その他の設定・編集 > ベストスコア表示) choosing ONE difficulty. Only usable with
  banapass/osaifu-keitai. **[WEB-gated]**
- **最近あそんだ曲 (recently-played) board** — added update8 (V2.09). Up to 5 songs. Card-only.
- **「もどる」(back) function** in difficulty select — added update10 (V4.06).
- **おすすめ (recommended) board** — added update13 (V7.05, 2013-01-30). Up to 5 songs; personalised
  with card.
- **段位道場 (dan dojo)** — added update13 (V7.05). donderhiroba dan dojo page added. **[WEB]**
  Then **removed** in V8.03 (2013-03-06) prep for Sorairo; returned in Sorairo.
- **Costumes (きせかえ)** — now force-applied (unlike 無印); selectable without card for 3 types
  (helicopter/ribbon/omikoshi); more via card.
- **Gold crown display bug** in early KATSU-DON (fixed V1.03); data still recorded on donderhiroba.
- High-score display in select **removed** in V8.03 prep; returned in Sorairo.

---
### 2. ソライロ (Sorairo) — launched 2013-03-13 (ST3100-1)

- **donderhiroba layout renewed**; "マイどん編集" page removed (now easier to reach). **[WEB]**
- **Friend list shows pending vs accepted** distinction. **[WEB]**
- **Profile publicity (gender / birthday)** — choose 非公開 / 自分だけ / 全員公開. **[WEB]**
  → maps to TaikOnline's "Public Profile" game setting. **First appears in Sorairo.**
- **Friends cap 55** (was 50). **[WEB]**
- 大会・挑戦状: "開催当日のみ" option added; play-option "おまかせ" added (update16). **[WEB]**
- **段位道場 (dan dojo)** returned (was disabled during KATSU-DON→Sorairo transition), update17.
- **真打 (Shin-uchi) mode** returned (update19).
- **Vocaloid genre** created.
- **Favourite song folder (お気に入り曲)** — referenced as an existing feature by Oct 2013
  (update21/22, songs unset from favourites on removal). **Present by Sorairo; absent in 無印.**
  Debut is either late KATSU-DON or Sorairo launch (not headlined in either page).
- High-score-in-select + 自己ベスト display **removed again** in V9.04 (Momoiro prep); returned in Momoiro.

### 3. モモイロ (Momoiro) — launched 2013-12-11 (ST4100-1)

**Major customization overhaul — the costume/title systems we know today start here.**

- **Costume slots (きせかえ overhaul)** — split into 3 combinable parts: **からだ (body) / あたま (head)
  / メイク (makeup)**. **からだ & あたま changeable on cabinet AND donderhiroba; メイク changeable
  ONLY on donderhiroba.** Old single costumes renamed **きぐるみ (kigurumi)**; some reassigned to a
  slot. **[WEB] — this is the website costume-setup feature; it does NOT exist pre-Momoiro.**
- **Reward shop (どんメダルショップ)** + **お買い物ポイント (shopping points)** added; both caps 30000. **[WEB]**
- **Title system overhaul:** titles become combinable from **称号パーツ (title parts)** obtained via
  **称号パーツガシャ (gacha, 200 pts/try)**. Pre-Momoiro whole titles can't be combined. **[WEB]**
- **Default play options auto-set** — pre-configure play options on donderhiroba (update30, V14.07,
  2014-03). **[WEB]** → matches code `supportsPlayOptions = version >= Momoiro` (debuts mid-Momoiro).
- **Dan dojo:** 飛び級 (skip-grade challenge) + new **達人 (Tatsujin)** rank (unlocked by passing 十段).
- Ranking tiebreak changed; title unset if donderhiroba not entered after Momoiro launch.
- Favourite folder (お気に入り曲) still present (referenced repeatedly).

### 4. キミドリ (Kimidori) — launched 2014-07-16 (ST5100-1)

- **Favourite folder (お気に入りフォルダ) in cabinet song-select — debuts here (V0.12).** Shows
  お気に入り曲 set on donderhiroba; **cap 5 songs** ("may expand later"). **[WEB-set]**
  Note: the underlying お気に入り曲 flag was already referenced in Sorairo (2013), but the
  select-screen folder itself is Kimidori.
- **特集フォルダ (featured/special folders)** added to song-select (themed song groupings).
- **太鼓塾 (Taiko-juku)** added — step-up practice for dan dojo; お題 changeable from donderhiroba.
  donderhiroba gets a 太鼓塾 page + 段位道場 page restored. **[WEB]**
- New low dan ranks **十級～五級**; 達人 still unlocked by passing 十段.
- **Top-3 best scores in select** — top 3 per difficulty cycle above self-best (line 308).
- Costume slots (からだ/あたま/メイク) + reward shop continue from Momoiro (e.g. ぷちキャラ, 音色 rewards).

### 5. ムラサキ (Murasaki) — launched 2015-03-11 (ST6100-1)

**Validates three existing TaikOnline code gates — all correct.**

- **Favourite folder cap 5 → 10** (V0.11, lines 97/114). ✓ matches `favoriteSongLimit()` (10 from Murasaki).
- **Default tone (音色) selectable/settable** (line 117). ✓ matches `supportsTone = >= Murasaki`. **[WEB]**
- **Cabinet ranking display difficulty selectable** (line 115). ✓ matches `supportsRankingDifficulty = >= Murasaki`. **[WEB]**
- Friends cap 55 → 60.
- New dan ranks **玄人 / 名人 / 超人** (between 十段 and 達人).
- 称号パーツガシャ + costume/tone rewards continue.

### 6. ホワイト (White) — launched 2015-12-10 (ST7100-1)

- **「むずかしさからえらぶ」(select-by-difficulty) folder — debuts here (V0.13, line 196).**
  Pick difficulty + ★ level; card-only. **→ This is the folder behind GameSettings'
  "Select by Difficulty Folder Presets" section; presets are White+.** **[WEB-config]**
- **とじる看板 (close-folder signboard)** added; **display on/off settable on donderhiroba** (line 195). **[WEB]**
- 最近あそんだ曲 ordering now reflects immediately (minor).
- Costumes now mostly きぐるみ rewards from songs (e.g. リラックス殺せんせー); slot system continues.
- No major donderhiroba overhaul in White.

### 7. レッド (Red) — launched 2016-07-14 (ST8100-1)

Mostly content (songs, きぐるみ rewards, 特集フォルダ); few system/website features.

- **Nameplate appearance varies by equipped title** — 4 styles: シブい紫 / 金ピカ / レインボー /
  いつもの (V1.x, line 123). **[WEB-relevant]** (title cosmetic styling).
- donderhiroba: gacha/reward point discounts, title rewards; no new web feature surfaces.
- Costume slot system + reward shop unchanged from Murasaki.

### 8. イエロー (Yellow) — launched 2017-03-15 (ST-9100-1)

Content-heavy; few website changes.

- **いっしょにワイワイ演奏** — new 2-player co-op mode (V2.x, line 211).
- **段位道場 外伝 (Gaiden)** introduced alongside regular dan dojo (line 73).
- **Seasonal reward shop** (どんメダル ごほうびショップ 春/夏/秋) — time-limited reward rotations.
- No donderhiroba/website feature overhaul; costume slot system unchanged.

### 9. ブルー (Blue) — launched 2018-03-15 (ST-10100-1)

Large content update; few new website features.

- **演奏バトル (Play Battle) mode** present — final stage + new special moves added; **絆レベル
  (bond level) cap 53 → 65** (line 342). Mode predates Blue (≈Yellow); evolves here.
- 段位道場 + 外伝 (Gaiden) continue.
- Seasonal reward shop continues; costume slot system unchanged.
- **This is the version of the `taiko-blue-experiment` branch.**

### 10. グリーン (Green) — launched 2019-03-14 (ST-11100-1)

Newest gen-3 version; TaikOnline's `default()`. Inherits the full mature feature set
(costume slots, reward shop, favourite folder cap 10, default tone/play-options, dan + 外伝,
select-by-difficulty folder, etc.).

- **演奏バトル (Play Battle) mode REMOVED** (line 75) — abolished vs Blue.
- **かつメダル (Katsu medal)** — new currency added alongside どんメダル for shop exchanges (line 77).
- 段位道場 + 外伝 continue; seasonal reward shop (グリーン春…).
- No new website customization surface beyond what Murasaki/White established.

---

## Summary matrix

Columns: 無=初代/無印, KA=KATSU-DON, ソ=Sorairo, モ=Momoiro, キ=Kimidori, ム=Murasaki, ホ=White,
レ=Red, イ=Yellow, ブ=Blue, グ=Green. ✓=present, ✗=absent, ~=partial/uncertain.
**[WEB]** = donderhiroba/website-side feature TaikOnline must gate.

| Feature | 無 | KA | ソ | モ | キ | ム | ホ | レ | イ | ブ | グ |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| donderhiroba site (tournaments/challenges) **[WEB]** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Don Challenge (ドンチャレ) **[WEB]** | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Titles (称号) | ~ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Title parts + gacha (称号パーツ) **[WEB]** | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Nameplate style varies by title **[WEB]** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ |
| Costume — single きせかえ (card) | ~ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Costume SLOTS からだ/あたま/メイク (web setup) [WEB]** | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| きぐるみ (full costume) | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Reward shop + points (どんメダル) **[WEB]** | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Katsu medal currency (かつメダル) **[WEB]** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ |
| Favourite-song flag (お気に入り曲) **[WEB]** | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Favourite FOLDER in select (cap 5) | ✗ | ✗ | ~ | ~ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Favourite folder cap 10 | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Best-score in select (needs web setting) **[WEB]** | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Best-3 scores in select | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Recently-played board (最近あそんだ曲) | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Recommended board (おすすめ) | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Dan dojo (段位道場) | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Taiko-juku (太鼓塾) **[WEB]** | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Dan Gaiden (段位道場 外伝) | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Default play options (web setup) **[WEB]** | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Default tone (音色) (web setup) **[WEB]** | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ranking display difficulty (web setup) **[WEB]** | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Profile publicity gender/birthday **[WEB]** | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Select-by-difficulty folder + presets **[WEB]** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Close-folder display toggle (web setup) **[WEB]** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ | ✓ | ✓ |
| 2P co-op (いっしょにワイワイ) | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Play Battle (演奏バトル) + bond level | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ~ | ✓ | ✗ |
| Friends cap | 30 | 50 | 55 | 55 | 55 | 60 | 60 | 60 | 60 | 60 | 60 |

> Uncertainties to confirm if precision matters:
> - Favourite folder: the **flag** appears Sorairo (2013), the in-cabinet **folder** Kimidori (V0.12).
>   Whether donderhiroba let you *set* favourites in Sorairo/Momoiro before the cabinet folder
>   existed is not explicit in the wiki — marked `~`.
> - Play Battle debut (Yellow vs Blue) is inferred from "cap raised" wording; marked `~` for Yellow.

---

## TaikOnline mismatches & required gating

Mapping the matrix to code. **Status: gating implemented** — enum helpers
(`supportsFavoriteFolder`/`supportsCostumeSlots`/`supportsProfilePublicity`/
`supportsPlayOptionDefaults`/`supportsToneDefault`/`supportsRankingDifficulty`/
`supportsDifficultyFolderPresets` + `featureSupport()`) drive server-side gating in
SongCatalog/Costume/Customize/GameSettings controllers, are shared to the frontend via
`taikoVersion.*.supports`, and hide the DonChan nav/page, favourite UI and gated GameSettings
fields. Findings below retained for reference; ✅ = done.

### A. Enum / modelling
1. **初代 (無印) not modelled.** [`TaikoGameVersion`](../../app/Enums/TaikoGameVersion.php) starts at
   `Katsudon`. If TaikOnline should ever serve 無印, it needs an enum case + protos; otherwise
   document explicitly that 無印 is out of scope. Its feature set is a strict subset of KATSU-DON
   minus Don Challenge / dan dojo / recommended / recently-played / best-score-in-select.
2. **`generation()` docstring is wrong** — says "Sorairo is oldest"; the enum's oldest is `Katsudon`.

### B. Favourite folder — assumed universal, isn't
3. [`favoriteSongLimit()`](../../app/Enums/TaikoGameVersion.php#L44) returns 5/10 for **all**
   versions including `Katsudon`, and [`SongCatalogController`](../../app/Http/Controllers/SongCatalogController.php#L175)
   serves favourites for any version. But the favourite folder does **not** exist in KATSU-DON, and
   the in-cabinet folder only appears in **Kimidori**. The `5 vs 10` cap split at Murasaki is
   **correct**, but the feature should be **gated off entirely for Katsudon** (and Sorairo/Momoiro
   unless the donderhiroba set-favourite flow is confirmed there). Recommend a
   `supportsFavoriteFolder()` (>= Kimidori, or >= Sorairo if confirmed) and disable the UI/endpoint
   below it.

### C. Costume setup — biggest gap (user's main concern)
4. **Website costume customization (からだ/あたま/メイク slots, きぐるみ, presets) only exists from
   Momoiro.** Pre-Momoiro (Katsudon, + 無印) had only single card-costumes with no website slot
   editor. The [`CostumeController`](../../app/Http/Controllers/Settings/CostumeController.php) /
   [`CustomizeController`](../../app/Http/Controllers/Settings/CustomizeController.php) and the
   settings UI should be **disabled for Katsudon (and Sorairo)** — those versions can't consume slot
   data. Add `supportsCostumeSlots()` (>= Momoiro) and hide/deny costume setup below it.
   - メイク (makeup) is **web-only** even where supported — keep that nuance.
5. **Title parts/gacha + reward shop** are also Momoiro+. If TaikOnline exposes any title-part or
   shop UI, gate it `>= Momoiro`.

### D. Game settings — already partly gated (validate)
6. [`GameSettingsController`](../../app/Http/Controllers/Settings/GameSettingsController.php) gates
   are **confirmed correct by the wiki**:
   - `supportsPlayOptions = >= Momoiro` ✓ (default play options settable on donderhiroba from Momoiro).
   - `supportsTone = >= Murasaki` ✓ (default tone from Murasaki).
   - `supportsRankingDifficulty = >= Murasaki` ✓ (ranking display difficulty from Murasaki).
7. **Profile publicity (gender/birthday public/private)** is **Sorairo+**. If the "Public Profile"
   setting is shown for Katsudon, gate it `>= Sorairo`.
8. **Select-by-difficulty folder presets** are **White+**, not universal. Gate that GameSettings
   section `>= White`.

### E. Boards / other
9. **Recommended + recently-played** boards are **Katsudon+** (fine for all modelled versions, but
   absent in 無印). **Best-score-in-select** is Katsudon+ too.
10. **Dan dojo** is Katsudon+ but was repeatedly disabled across version transitions; **Taiko-juku**
    is Kimidori+, **Gaiden** is Yellow+. If TaikOnline exposes juku/gaiden, gate `>= Kimidori` /
    `>= Yellow`.
11. **Nameplate-style-by-title** is Red+; **Katsu medal** currency is Green-only.

### Suggested enum helpers to add
```
supportsFavoriteFolder(): >= Kimidori   (or >= Sorairo if web-set favourites confirmed)
supportsCostumeSlots():   >= Momoiro    // からだ/あたま/メイク + きぐるみ + reward shop
supportsTitleParts():     >= Momoiro
supportsProfilePublicity(): >= Sorairo
supportsDifficultyFolderPresets(): >= White
supportsTaikoJuku():      >= Kimidori
supportsDanGaiden():      >= Yellow
```


