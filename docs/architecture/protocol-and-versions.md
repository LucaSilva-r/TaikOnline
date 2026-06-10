# Protocol & versions

The server speaks nine distinct protobuf dialects, one per Taiko release. Although
the message *names* repeat across versions (every version has a
`HeartBeatResponse`, a `PlayResultRequest`, …), the field layouts differ, so the
dialects cannot share a single set of generated classes.

## Version enum

`App\Enums\TaikoGameVersion` is the single source of truth:

| case | label | update id | route major |
|------|-------|-----------|-------------|
| sorairo | SORAIRO | ST3100-1 | v03 |
| momoiro | MOMOIRO | ST4100-1 | v04 |
| kimidori | KIMIDORI | ST5100-1 | v05 |
| murasaki | MURASAKI | ST6100-1 | v06 |
| white | WHITE | ST7100-1 | v07 |
| red | RED | ST8100-1 | v08 |
| yellow | YELLOW | ST-9100-1 | v09 |
| blue | BLUE | ST-10100-1 | v10 |
| green | GREEN | ST-11100-1 | v11 |

The cabinet puts its route major in the request URL (`/v11r01/chassis/...`).
`TaikoGameVersion::fromRouteVersion()` maps that prefix back to a case; the database
`game_version`/`version` columns store the lowercase `value`.

## Generated protobuf namespaces

Source protos live in `protobuf/<version>/{taiko,vsinterface}.proto`, extracted
verbatim from each game EBOOT. `scripts/generate-protobuf.sh` injects, per version:

- a distinct package (`taiko.<version>`) so the descriptor pool gets unique
  fully-qualified symbols and all nine dialects can register in one process;
- a `php_namespace` of `App\GameProtocol\Proto\<Studly>\{Taiko,VsInterface}`.

> The `php_namespace` is embedded as a length-prefixed string inside each
> `Metadata` file's serialized `FileDescriptorProto`. Never hand-edit it — rerun
> the generator, which lets `protoc` recompute the length prefixes.

The script also downgrades proto2 `required` fields to `optional` (Google's PHP
generator rejects `required`; the wire format is unchanged).

## Message resolution

`App\GameProtocol\Support\ProtocolMessageResolver::class($version, $name, $group)`
builds the fully-qualified class name by interpolation — there is no per-version
switch. `MessageWriter::fill()`/`set()` tolerantly skip setters that are absent on a
given dialect, which absorbs field renames between versions
(`all_play_cnt` vs `app_play_cnt`).

## Where dialects genuinely diverge

Tolerant setters handle *renames*. They do **not** handle *structural* differences,
which are handled in the [handler layer](request-handling.md):

- Green's `PlayResultRequest` is a thin wrapper carrying a gzip-compressed
  `PlayResultDataRequest` blob; blue/red send the play result inline.
- Blue's `InitialdatacheckResponse` has a different shape and is hand-serialised.
