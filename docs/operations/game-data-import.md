# Game data import

Song catalogs and dan courses are imported from arcade dumps with Artisan commands.
Both follow the same shape: optionally sync the source XML out of a dump, then parse
it into version-scoped tables.

## Commands

```bash
# Songs (musicinfo.xml)
php artisan app:import-songs {version?} {--source=<dump root>}

# Dan courses (musicmedleyinfo.xml)
php artisan app:import-dan-courses {version?} {--source=<dump root>}
```

Omit the version argument to import every version. With `--source`, the command
copies the correct XML out of the dump into `storage/app/game-data/<version>/` before
importing; without it, the command reads whatever is already staged there.
Re-importing a version is destructive for that version (delete and rebuild).

## Dump layout

Dumps nest a serial-id folder between the colour folder and `USRDIR`:

```
<dump root>/<COLOUR>/<SERIAL>/USRDIR/data/musicmedleyinfo.xml
                                        /data/config/<board>/musicmedleyinfo.xml
```

The import copies each version's dojo from a fixed location
(`ImportDanCoursesCommand::medleySource`):

| version | dump | file under `USRDIR/data` |
|---------|------|--------------------------|
| sorairo, momoiro | own | `musicmedleyinfo.xml` |
| kimidori | GREEN | `config/ST5100-1/musicmedleyinfo.xml` |
| murasaki | GREEN | `config/ST6100-1/musicmedleyinfo.xml` |
| white | GREEN | `config/ST7100-1/musicmedleyinfo.xml` |
| red | GREEN | `config/ST8100-1/musicmedleyinfo.xml` |
| yellow | own | `config/ST9100-1/musicmedleyinfo.xml` |
| blue | own | `config/S10100-1/musicmedleyinfo.xml` |
| green | own | `config/S11100-1/musicmedleyinfo.xml` |

> **Kimidori to Red must not use their own colour dump.** Each of those dumps ships
> the *previous* version's dojo (the KIMIDORI dump has Momoiro's, MURASAKI has
> Kimidori's, WHITE has Murasaki's, RED has White's). Song `uniqueid`s are renumbered
> between eras (`wego` is 338 in Sorairo/Momoiro, 366 in Kimidori, gone later), so a
> wrong-era dojo points at missing or *different* songs and can crash the cabinet.
>
> To check a pick, match every course song's `(musicid, uniqueid)` pair against the
> version's own `musicinfo.xml`. Remaining mismatches with the picks above are songs
> added after the dump was taken: one in kimidori dan 22 (`souryu`), one in white
> dan 25 (`vt1b2x`). They are not in those versions' song catalogs, so `taikojuku`
> drops them when serving.

The dan importer parses with `LIBXML_RECOVER`, because some dumps (yellow) ship a
truncated final entry; the well-formed courses still import.

## The Sail filesystem caveat

Database access runs through the Postgres container, so imports are run with
`./vendor/bin/sail artisan …`. The container only mounts the project directory, so a
dump under, say, `/mnt/shared` is **not** visible inside it. Two options:

1. **Stage host-side.** Copy each version's file into the project (visible via the
   `.:/var/www/html` mount), then import without `--source`:

   ```bash
   cp <dump>/.../musicmedleyinfo.xml storage/app/game-data/<version>/
   ./vendor/bin/sail artisan app:import-dan-courses
   ```

2. **Bind-mount the dumps.** Add a read-only mount of the dump root to the
   `laravel.test` service in `compose.yaml`, then use
   `--source=/path/inside/container`.

## Known import results (dan courses)

A full import of the reference dumps yields, per version:

```
sorairo 5 · momoiro 15 · kimidori 22 · murasaki 25 · white 25 · red 25 · yellow 25 · blue 25 · green 25
```

Course counts grow with the release era, which matches the games' history.

## Waddamburo charts (stock + Nijiiro, ranked)

Waddamburo's own stock and Nijiiro charts count towards the rankings. Export them once from a client that
has the game data and the Nijiiro folder (`nijiiro_folder` in its settings), then import it on the server
(the file is too large for a web upload):

```bash
Waddamburo --export-charts=charts.jsonl.gz   # gzipped JSON lines, one chart import each
php artisan app:import-waddamburo-charts charts.jsonl.gz
```

Every chart is created (notes hash-checked) or, if already known, ranked; standings are recomputed.
Re-running is safe. Charts first seen through plays
(TJA, osu!) still start unranked.
