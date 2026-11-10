# TaikOnline Documentation

Server that restores online functionality for every arcade release of *Taiko no
Tatsujin*. One Laravel application serves all nine dialects (Sorairo → Green) by
resolving the cabinet's version from the request and dispatching to version-aware
handlers.

These documents describe how the server is built and how each game feature is
implemented. They are reference material, kept close to the code — when a document
and the code disagree, the code wins and the document should be fixed.

## Player wiki

The public Waddamburo player wiki is served at `/waddamburo/wiki`. Its guides live
in [`resources/wiki`](../resources/wiki); `WikiController` holds their titles,
summaries, search keywords, and reading order. Edit the Markdown for content changes.
Use relative article slugs for links between guides so navigation keeps the selected
site scope. Level-two headings become the article section links automatically.

Wiki screenshots are deployment media in `storage/app/public/wiki/`, served as
`/storage/wiki/`; they are ignored by Git, like the site's other game media. Copy
that directory when deploying the wiki. Use descriptive filenames and Markdown
image links with alt text, followed by an italic caption. Link each image to its
full-size file so readers can inspect the controls. Images load lazily. Keep raw
research captures, account credentials, and private paths out of published media.

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
- [Waddamburo multiplayer](features/multiplayer.md) — player status so far; peer-to-peer play is planned.
- [Rules and Privacy](features/legal.md) — what players must accept, and how it blocks the site and uploads.
- [Cabinet tokens](features/cabinet-tokens.md) — admin- and player-issued Waddamburo cabinet tokens, terms gate, disable/revoke/ban.
- [Moderation](features/moderation.md) — moderator role, sanctions and shadowbans,
  play hiding, reports, the replay inspector (design).

### Operations
- [Game data import](operations/game-data-import.md) — importing songs and dan
  courses from arcade dumps, including the Sail filesystem caveat.
- [Costume asset extraction](operations/costume-asset-extraction.md) — the offline
  pipeline that turns arcade dumps into the costume picker's per-version
  spritesheets: nutdata formats (NUT/NDP/DDP), the NUD 3D format, where each
  costume part lives, and the extract → pack scripts.

## Conventions

- **Version** means a Taiko release (the `TaikoGameVersion` enum: `sorairo`,
  `momoiro`, `kimidori`, `murasaki`, `white`, `red`, `yellow`, `blue`, `green`).
- **Dialect** means that version's protobuf schema.
- Wire field names are quoted as the cabinet sends them (`get_dan`, `tone_flg`).
