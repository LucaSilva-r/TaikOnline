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

The import resolves a version's file in its **own** colour dump:

1. the `data/config/<board>/` variant whose folder name contains the version's
   board number;
2. otherwise the base `data/musicmedleyinfo.xml`.

The order matters for Blue and Green: their base files contain the same legacy
16-course catalog, while `S10100-1` and `S11100-1` contain the current 25 normal
Dan slots. Other base-only eras continue to use their base file.

> Do **not** read another dump's `config/<board>` file. Those are a newer cabinet's
> view of an older board and differ — e.g. the green dump's murasaki board has 25
> courses while murasaki's own dump has 22. The authoritative data for a version is
> its own dump.

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
sorairo 5 · momoiro 15 · kimidori 16 · murasaki 22 · white 25 · red 25 · yellow 25 · blue 25 · green 25
```

Course counts grow with the release era, which matches the games' history.
