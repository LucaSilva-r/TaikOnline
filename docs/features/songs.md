# Song Catalog

This document describes how songs are imported, stored, and managed in TaikOnline.

## Overview

Songs come from arcade game data files (`musicinfo.xml`) exported from real Taiko no Tatsujin Green cabinets. Each version (e.g., "green") has its own XML file with potentially different song IDs even for the same track.

```
storage/app/game-data/
├── green/
│   └── musicinfo.xml     # 853 songs, version = "green"
├── ai_tuninginfo.bin
├── musicinfo.bin         # binary master data
└── ...
```

## Database Schema

### `songs` table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Internal ID |
| `version` | string | Version identifier ("green", "blue", etc.) |
| `song_no` | int | Maps to cabinet protocol's `song_no` (= uniqueid from XML) |
| `music_id` | string | XML `musicid` field (e.g., "ynzums") |
| `unique_id` | int | Same as song_no, kept explicit for clarity |
| `title` | string | Original Japanese/English title from XML |
| `title_en` | string (nullable) | Localized English title |
| `genre` | string | Enum value: stored as lowercase slug (e.g., "jpop") |
| `partsset` | string | Enum value: e.g., "taiko", "miku", "lovelive" |
| `wai2_partsset` | string | Alternative parts set enum |
| `flags` | json | hasextreme, papamama, secret, newrelease, demoplay |
| `tags` | json | 16 integers of metadata tags |
| `created_at` | timestamp | Import time |
| `updated_at` | timestamp | Last update time |

**Indexes:**
- Unique on `(version, music_id)` — prevents duplicate imports
- Index on `(version, song_no)` — fast lookups by version and song number

## Versioning Strategy

Each arcade version has its own set of songs with potentially different `song_no` values for the same track. The `version` column partitions the catalog:

```sql
-- Green version songs
SELECT * FROM songs WHERE version = 'green';

-- Blue version songs (when added)
SELECT * FROM songs WHERE version = 'blue';
```

### Cross-version song matching

Same `music_id` across versions indicates the same song. Use this for linking rankings or showing "available in" links:

```php
// Find all versions where this song exists
Song::where('music_id', $musicId)->pluck('version');

// Example: a song with music_id "ynzums" might exist in both green and blue
```

For custom songs, set `title_en` to match an existing track's normalized title for manual linking.

## Import Process

### Command

```bash
php artisan app:import-songs {version}
```

**Parameters:**
- `{version}` — Directory name under `storage/app/game-data/` (e.g., "green")

**Example:**
```bash
php artisan app:import-songs green
```

This reads from `storage/app/game-data/green/musicinfo.xml` and upserts all songs.

### What gets imported

Each `<Data>` element in the XML produces one song record:

| XML Field | DB Column | Notes |
|-----------|-----------|-------|
| `musicid` | `music_id` | Unique identifier per version |
| `uniqueid` | `song_no`, `unique_id` | Cabinet protocol uses this as song_no |
| `musicname` | `title` | Original title from XML |
| `genrename` | `genre` | Mapped to `SongGenre` enum |
| `partsset` | `partsset` | Mapped to `SongPartsSet` enum |
| `wai2partsset` | `wai2_partsset` | Mapped to `SongWai2PartsSet` enum |
| (16x) `tag` | `tags` | JSON array of 16 integers |

### Flags

Boolean flags from XML stored as JSON:

```json
{
    "hasextreme": false,
    "papamama": false,
    "secret": true,
    "newrelease": false,
    "demoplay": false
}
```

### Upsert behavior

The import uses `music_id` + `version` as the unique key. If a song already exists:
- All fields are overwritten with the new XML data
- This is useful for re-importing after adding new genres/parts sets to enums

### Error handling

If a genre, parts set, or wai2-parts-set value from the XML doesn't match any enum case, the import will warn and skip that song. Check the output for messages like:

```
[42] Unknown genre 'FooBar' for song example1
[15] Unknown partsset 'custom_set' for song custom_song_01
```

## Enums

Category values are stored as enum slugs, not raw XML strings. This enables type-safety and future translations.

### SongGenre (9 cases)

| Case | DB Value | XML Source | English Label |
|------|----------|------------|---------------|
| `Jpop` | `'jpop'` | `'J-POP'` | J-POP |
| `Anime` | `'anime'` | `'アニメ'` | Anime |
| `Classical` | `'classical'` | `'クラシック'` | Classical |
| `GameMusic` | `'game_music'` | `'ゲームミュージック'` | Game Music |
| `NamcoOriginal` | `'namco_original'` | `'ナムコオリジナル'` | Namco Original |
| `Variety` | `'variety'` | `'バラエティ'` | Variety |
| `Vocaloid` | `'vocaloid'` | `'ボーカロイド'` | Vocaloid |
| `Medley` | `'medley'` | `'メドレー'` | Medley |
| `ChildrensSongs` | `'childrens_songs'` | `'童謡'` | Children's Songs |

Adding a new genre requires:
1. Adding the case to `app/Enums/SongGenre.php`
2. Updating `fromXml()` mapping with the XML string value
3. Optionally adding `label()` and `labelJp()` entries

### SongPartsSet (32 cases)

Covers all part set categories from the green version XML: Taiko original, franchise crossovers (Idolmaster, Love Live!, Yo-kai Watch, etc.), character sets (Miku, Kumamon), and others.

Each case maps an XML string value to a snake_case enum constant, with `label()` returning human-readable text.

### SongWai2PartsSet (8 cases)

Alternative parts set for special modes: A3, A3 Encore, Idolmaster variants, Poptep, Taiko Original.

## Custom Songs

Future support for custom songs will follow the same import pattern:
- User creates a `musicinfo.xml` file in a new version directory
- Runs `php artisan app:import-songs {custom-version}`
- Custom songs can link to existing tracks via matching `music_id` or manual title matching

## Admin UI

The admin panel at `/admin/songs` displays the full song catalog with:
- Song number, original title, English title
- Genre and parts set badges
- EX (extreme) and PM (papamama) flag indicators

## Related Models

### `SongPlayResult`

Stores gameplay results. References songs via `song_no`:

```php
// Get a song from a play result
$result = SongPlayResult::first();
$songNo = $result->song_no;  // matches unique_id/song_no in songs table
```

Note: `SongPlayResult.song_no` is an unsigned integer that corresponds to `Song.unique_id` (which equals `Song.song_no`).

### `SongBest`

Stores per-player best scores. Also references `song_no`:

```php
$best = SongBest::first();
$songNo = $best->song_no;  // matches unique_id/song_no in songs table
```

## Adding a New Version

To add support for a new arcade version (e.g., "blue"):

1. Place the `musicinfo.xml` file at `storage/app/game-data/blue/musicinfo.xml`
2. Add any missing enum values to the relevant enums if the new version has genres or parts sets not yet covered
3. Run the import: `php artisan app:import-songs blue`
4. The songs will be partitioned under `version = 'blue'`

## Data Flow Diagram

```
[arcade XML file] → ImportSongsCommand → [songs table]
        │                  │                      │
   musicinfo.xml    parses & validates     version + music_id (unique)
   └── green/             │                  └── song_no = uniqueid
                          ▼
                   Song::create/update()
                         │
              enums: genre, partsset, wai2_partsset
                         │
              JSON: flags (5 booleans), tags (16 ints)
```

## Maintenance

### Checking import status

```bash
# Count songs per version
php artisan tinker --execute="echo App\Models\Song::groupBy('version')->count();";

# Check for any unknown enum values in logs after re-import
php artisan app:import-songs green 2>&1 | grep "Unknown"
```

### Updating enums

When new genres or parts sets appear in future XML files:

1. Add the case to the appropriate enum file
2. For `SongGenre`: update `fromXml()` with the mapping from XML string → enum case
3. Run the import again — existing records will be updated with new values if applicable

### Clearing and re-importing

```bash
# Truncate all songs for a version and re-import
php artisan tinker --execute="App\Models\Song::where('version', 'green')->delete();"
php artisan app:import-songs green
```
