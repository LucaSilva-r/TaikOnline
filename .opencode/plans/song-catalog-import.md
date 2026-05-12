# Song Catalog Import Plan

## Goal
Import music metadata from `storage/app/game-data/green-specific/musicinfo.xml` into a `songs` database table to enable song exploration and version-specific rankings.

## XML Data Overview
- **853 songs** in the green-specific XML file
- Each song has: `musicid` (string ID), `uniqueid` (numeric ID), `title`, `genrename`, flags (`hasextreme`, `papamama`, `secret`, `newrelease`, `demoplay`), `partsset`, `wai2partsset`, and 16 `<tag>` integers
- **9 genres**: J-POP, アニメ, クラシック, ゲームミュージック, ナムコオリジナル, バラエティ, ボーカロイド, メドレー, 童謡
- **30 partssets**: A3, GMT, animal, butto, dojo, gumi, i7id7, i7natsu, i7rev, i7tri, ia, imas, imasCG, imasML, imasSideM, kinbaku, kobayashi, kumamon, lovelive, mario, miku, mikugumi, pzd, taiko, toho, tt, ymck, yokai, yokai_hatsukoi, yokai_matsuri, yokai_tokoroten, yokai_yougota
- **8 wai2partssets**: A3, A3_02, i7id7, i7natsu, i7rev, i7tri, poptep, taiko

## Design Decisions

### song_no = uniqueid
Cabinet protocol sends `song_no` in play results. Mapping it directly to XML's `uniqueid` preserves the original arcade numbering and lets us resolve song references from gameplay data.

### Single table + version column
Rather than per-version tables, use a single `songs` table with a `version` column ('green', 'blue', etc.). This simplifies management while keeping version isolation through indexing.

### Cross-version identification
Same `music_id` across versions indicates the same song. For custom songs later, user can specify a shared `music_id`. Frontend can show "view in other versions" by matching `music_id`.

### Enums for categories
Genre (9 values), partsset (30 values), and wai2_partsset (8 values) are finite sets — best modeled as Laravel castable enums. This gives type-safety, autocomplete, and a single place to add per-version translations/localization labels later.

## Implementation Steps

### 1. Migration — Create `songs` table
```php
Schema::create('songs', function (Blueprint $table) {
    $table->id();
    $table->string('version');           // 'green', 'blue', etc.
    $table->unsignedInteger('song_no');   // = uniqueid from XML
    $table->string('music_id');           // XML musicid (e.g., 'ynzums')
    $table->unsignedInteger('unique_id'); // same as song_no, explicit
    $table->string('title');              // Japanese/original title
    $table->string('title_en')->nullable(); // English/localized title
    $table->string('genre');              // mapped to SongGenre enum
    $table->string('partsset');           // mapped to SongPartsSet enum
    $table->string('wai2_partsset');      // mapped to SongWai2PartsSet enum
    $table->jsonb('flags')->default('{}');// hasextreme, papamama, secret, newrelease, demoplay
    $table->jsonb('tags')->default('[]'); // 16 integer tag values
    $table->timestampsTz();

    $table->unique(['version', 'music_id']);
    $table->index(['version', 'song_no']);
});
```

### 2. Enums (`app/Enums/SongGenre.php`, `app/Enums/SongPartsSet.php`, `app/Enums/SongWai2PartsSet.php`)
Laravel 13 castable enums using the string-backed approach:
- Each enum maps XML string value → PHP case name (snake_case from hyphenated values like `yokai_hatsukoi`)
- Add `label(string $locale)` method for future translation support
- Genre example: `Jpop`, `Anime`, `Classical`, `GameMusic`, `NamcoOriginal`, `Variety`, `Vocaloid`, `Medley`, `ChildrensSongs`
- Partsset: 30 cases including `Taiko`, `Miku`, `imas`, `imasSideM`, `lovelive`, `mario`, etc.

### 3. Song Model (`app/Models/Song.php`)
- Fillable attributes matching table columns
- JSON casts for `flags` and `tags`
- Enum casts for `genre`, `partsset`, `wai2_partsset` via `$casts`:
  ```php
  protected $casts = [
      'genre' => SongGenre::class,
      'partsset' => SongPartsSet::class,
      'wai2_partsset' => SongWai2PartsSet::class,
      'flags' => 'array',
      'tags' => 'array',
  ];
  ```
- Accessor: `title_clean()` — fullwidth→halfwidth, lowercase normalization for cross-version matching

### 4. Artisan Command (`app/Console/Commands/ImportSongsCommand.php`)
```
php artisan app:import-songs {version} [--dry-run] [--force]
```
- Reads `{data_path}/{version}/musicinfo.xml`
- Parses with SimpleXML
- Enum string→enum conversion for genre/partsset/wai2_partsset
- `--dry-run`: shows stats without modifying DB
- `--force`: updates existing songs (upsert)
- Reports: created, updated, skipped counts per version

### 5. Tests (`tests/Feature/ImportSongsTest.php`)
- Parse all 853 songs from green-specific XML
- Verify correct field mapping for each column type
- Verify enum resolution works for all known genre/partsset/wai2_partsset values
- Test upsert behavior on re-import
- Test dry-run mode (no DB changes)
- Test version isolation

## File Locations
- Migration: `database/migrations/2026_05_12_000000_create_songs_table.php`
- Enums: `app/Enums/SongGenre.php`, `app/Enums/SongPartsSet.php`, `app/Enums/SongWai2PartsSet.php`
- Model: `app/Models/Song.php`
- Command: `app/Console/Commands/ImportSongsCommand.php`
- Test: `tests/Feature/ImportSongsTest.php`

## Future Considerations
- Custom songs: user creates XML files in game-data dir, runs import command
- Cross-version ranking display: match by music_id or normalized title
- Link play results to songs via foreign key on id (not song_no) for efficiency
- Potential admin UI for managing custom songs (separate feature)
- Per-version genre translation: enum `label()` method can accept locale param
