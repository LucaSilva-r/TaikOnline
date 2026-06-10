# Dan dojo (taikojuku)

The dan dojo (段位道場 / *taikojuku*) is a ranked challenge mode: a player attempts a
fixed set of songs back-to-back under pass conditions to earn a dan rank. Each
challenge level (`get_dan`, 1–25) is a **course** the server defines.

## What the server controls vs the cabinet

This is the key fact for authoring courses:

- **The server chooses the songs.** The `taikojuku.php` response carries, per dan
  slot, the list of `(song_no, level)` to play.
- **The cabinet evaluates pass/fail.** The cabinet has its own bundled
  `musicmedleyinfo` datatable and reads the pass conditions (soul gauge, hit/miss
  thresholds, score) for that dan slot from it. The server does not send conditions.

So the song list is fully server-authored, while the pass thresholds for a given dan
slot come from whatever the cabinet shipped with. A course is therefore reproducible
from a tiny amount of data — you do **not** need a dump to define one, only the
`(dan, [songs], verup_no)` tuple.

## Protocol flow

`taikojuku.php` request → `TaikojukuRequest { get_dan[] }` (the dan slots the cabinet
wants). Response → `TaikojukuResponse`:

```
TaikojukuResponse
  result = 1
  ary_jukupack_data[]            # one per course
    get_dan                      # challenge level 1..25
    verup_no                     # data version counter
    ary_jukusong_data[]          # up to 10 songs
      song_no
      level
```

Handled by `GameHandler::taikojuku` (shared by all versions). It filters requested
slots to 1–25, returns the matching courses for the version, and returns every course
when no slot is requested.

## Storage

Two version-scoped tables, populated by import or by a seeder:

- **`dan_courses`** — `version`, `dan` (challenge level), `unique_id`, `name`,
  `difficulty`, `verup_no`. Unique per `(version, dan)`.
- **`dan_course_songs`** — `dan_course_id`, `song_no`, `level`, `sort_order`.

Models: `DanCourse` (with an ordered `songs` relation) and `DanCourseSong`.

## Source format: `musicmedleyinfo.xml`

The arcade datatable is a Boost-serialized XML. Each course:

```xml
<MusicMedleyInfoData>
  <uniqueid>20000</uniqueid>          <!-- course id -->
  <medleyname>初級</medleyname>        <!-- display name -->
  <difficulty>3</difficulty>          <!-- course difficulty -->
  <challengelv>1</challengelv>        <!-- dan slot == get_dan -->
  <Content>                           <!-- a song; repeated -->
    <musicid>wego</musicid>
    <uniqueid>338</uniqueid>          <!-- == song_no -->
    <difficulty>0</difficulty>        <!-- == level (echoed verbatim) -->
    <notes>141</notes>
  </Content>
  <Conditions>...</Conditions>            <!-- pass thresholds (cabinet-side; not stored) -->
  <ExcellentConditions>...</ExcellentConditions>
</MusicMedleyInfoData>
```

Mapping into our tables:

| our field | source |
|-----------|--------|
| `dan` | `challengelv` |
| `song_no` | `Content/uniqueid` (entries with `uniqueid == 0` are dropped) |
| `level` | `Content/difficulty` (echoed; the cabinet interprets it) |
| `name`, `difficulty`, `unique_id` | the matching `MusicMedleyInfoData` fields |
| `verup_no` | not in the file; set to `1` |

`Conditions`/`ExcellentConditions` are intentionally not stored — the cabinet owns
pass evaluation.

## Authoring courses without a dump

Because a course is just a dan slot plus a song list, the server can define dan
dojos for any version directly — for example, a seeder or an admin tool that writes
`dan_courses` + `dan_course_songs`:

```php
$course = DanCourse::create([
    'version' => 'green', 'dan' => 1, 'unique_id' => 90001,
    'name' => 'Custom Beginner', 'difficulty' => 3, 'verup_no' => 1,
]);
$course->songs()->createMany([
    ['song_no' => 338, 'level' => 3, 'sort_order' => 0],
    ['song_no' => 604, 'level' => 3, 'sort_order' => 1],
]);
```

The cabinet will play those songs for dan 1 and judge the run against its own dan-1
pass conditions. Practical limits: 1–25 dan slots, up to 10 songs per course, and
`song_no` values that exist in that version's catalog.

For importing real courses from arcade dumps, see
[game data import](../operations/game-data-import.md).

## Progress (not yet implemented)

Earning and displaying dan ranks — persisting the cabinet's `dan_result`/`play_dan`
from the play result and reflecting it in BAID (`got_dan_flg`, `got_dan_max`,
`disp_dan_type`) and userData (`disp_taikojuku_dan`) — is the remaining piece.
