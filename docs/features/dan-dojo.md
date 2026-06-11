# Dan dojo (taikojuku)

The dan dojo (段位道場 / *taikojuku*) is a ranked challenge mode: a player attempts a
fixed set of songs back-to-back under pass conditions to earn a dan rank. Each
challenge level (`get_dan`, 1–25) is a **course** the server defines.

## Source of truth: the cabinet's local file

> **The cabinet reads its dan dojo from the local `musicmedleyinfo.xml` file, not
> from the server.** The `taikojuku.php` protocol response is *not* consumed by the
> cabinet today — the online data-update path that would deliver it has not been
> made to work. To actually change a cabinet's dojo you replace its
> `musicmedleyinfo.xml` (the operator has file authority through Taiko Zucchini). A
> server-driven push API is possible but out of scope for now.

What this means for this server:

- The `dan_courses` tables, the import command, and the `taikojuku.php` handler are a
  **catalog and authoring layer**. They record and generate dan courses, and the
  handler is the correct protocol behaviour for if/when the update path works — but
  changes here do not reach a cabinet on their own.
- A course is fully described by `(dan, [songs (song_no, level)], verup_no)` plus, in
  the file, the pass conditions. Pass/fail is evaluated entirely on the cabinet from
  the file's `Conditions`/`ExcellentConditions`.

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

### Daily-challenge randomizer

`App\Services\DanCourseRandomizer` is the reference implementation of server
authoring: `randomize($version)` replaces a version's courses with a fresh set
(10 courses × 3 songs) drawn at random from that version's `songs` catalog, bumping
`verup_no` to `time()` so the cabinet re-reads. It needs no datatable — only
imported songs — which is what makes it usable for daily challenges on any version.

> **Level must be a chart the song actually has.** The dojo `level` is the chart
> difficulty: 0 easy, 1 normal, 2 hard, 3 oni, 4 ura. Real datatables use 0–3 in
> every version; ura (4) appears only in the murasaki/white/red/yellow dojos and
> only rarely, always paired with a song that has an ura chart. Green and blue never
> use ura. Assigning a level the picked song does not have makes the cabinet
> **crash** when it opens the dojo (this is what `level = 4` on random green songs
> did). The randomizer caps at 3 (oni is near-universal); emitting 4 would require
> restricting picks to `hasextreme` songs.

The admin **Dan Dojo** page (`/admin/dan-dojo`,
`Admin\DanDojoController`) lists every version's published courses and exposes a
per-version **Randomize** button that calls the service. A version with no imported
songs is skipped.

> Because the cabinet reads the dojo from its local file (see above), randomizing
> updates the server's catalog but does not change a running cabinet on its own. To
> deploy a generated set you would render it to a `musicmedleyinfo.xml` — including
> per-course pass conditions, which the randomizer does not yet produce — and load
> that file onto the cabinet. That export/deploy step is future work.

For importing real courses from arcade dumps, see
[game data import](../operations/game-data-import.md).

## Progress (not yet implemented)

Earning and displaying dan ranks — persisting the cabinet's `dan_result`/`play_dan`
from the play result and reflecting it in BAID (`got_dan_flg`, `got_dan_max`,
`disp_dan_type`) and userData (`disp_taikojuku_dan`) — is the remaining piece.
