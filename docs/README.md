# TaikOnline Documentation

Server that restores online functionality for every arcade release of *Taiko no
Tatsujin*. One Laravel application serves all nine dialects (Sorairo → Green) by
resolving the cabinet's version from the request and dispatching to version-aware
handlers.

These documents describe how the server is built and how each game feature is
implemented. They are reference material, kept close to the code — when a document
and the code disagree, the code wins and the document should be fixed.

## Index

### Architecture
- [Overview](architecture/overview.md) — the whole application: web surface and
  cabinet protocol surface, runtime shape, routing.
- [Protocol & versions](architecture/protocol-and-versions.md) — how nine protobuf
  dialects coexist, version resolution, the generated namespaces.
- [Request handling](architecture/request-handling.md) — routes, the thin
  controller, and the per-version handler strategy.

### Data model
- [Player state](data-model/player-state.md) — what is shared per card vs scoped
  per game version, and the tables that hold it.

### Features
- [Songs](features/songs.md) — the song catalog: schema, import, management.
- [Crowns](features/crowns.md) — clear/full-combo medals on the song wheel.
- [Cosmetics](features/cosmetics.md) — costume/tone/title unlocks, equipped
  costume, and last-used settings.
- [Dan dojo](features/dan-dojo.md) — the taikojuku course catalog, its data
  format, and how to author courses without a dump.

### Operations
- [Game data import](operations/game-data-import.md) — importing songs and dan
  courses from arcade dumps, including the Sail filesystem caveat.

## Conventions

- **Version** means a Taiko release (the `TaikoGameVersion` enum: `sorairo`,
  `momoiro`, `kimidori`, `murasaki`, `white`, `red`, `yellow`, `blue`, `green`).
- **Dialect** means that version's protobuf schema.
- Wire field names are quoted as the cabinet sends them (`get_dan`, `tone_flg`).
