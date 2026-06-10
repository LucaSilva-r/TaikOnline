# Cosmetics

Cosmetics cover what a player has unlocked and what they are wearing: costumes,
sound tones, and titles. Because the id of an item differs between versions,
everything here is stored on the version-scoped `player_cosmetics` row (see
[player state](../data-model/player-state.md)).

## Unlock flags

Each category is sent to the cabinet as a fixed-size bitset where **each unlocked id
maps directly to its bit** (id 0 → byte 0 bit 0 — no offset, unlike song flags which
are `song_no - 1`). Built by `ScoreMapper::idFlagBytes($ids, $bytes)`.

| category | wire field | response | size (bytes) | stored in |
|----------|------------|----------|--------------|-----------|
| costume slot 1–5 | `costume_flg_1..5` | BAID | 32 each | `unlocked_costumes` `{slot => [ids]}` |
| tone | `tone_flg` | userData | 16 | `unlocked_tones` |
| title | `title_flg` | userData | 128 | `unlocked_titles` |

The five costume slots are kigurumi, head, body, face, puchi.

## Granting unlocks

A play result reports newly granted ids, which `PlayResultService::persistCosmetics`
merges into the stored lists:

- `get_tone_no` → `unlocked_tones`
- `get_title_no` → `unlocked_titles`
- `get_costume_no_1..5` → `unlocked_costumes[slot]`
- `release_song_no` → `players.unlocked_song_numbers` (shared, song unlocks)

Getters are guarded with `method_exists` because older dialects omit some fields.

## Equipped costume

The cabinet sends the currently-worn costume as `ary_current_costume` in the play
result. It is mirrored into `costume_1..5` and returned on BAID as `ary_costumedata`,
so the look persists across sessions for that version.

## Last-used tone and options

The play result's final stage carries the tone and play options the player used:

- `tone_flg` (a bitset; the set bit is the equipped tone id) → `default_tone_setting`
- `option_flg` (a little-endian flag value) → `default_option_setting`

These are persisted as the player's defaults, so the cabinet pre-selects them next
session. This is the only protocol-driven equip loop for tone/options.

## Title (display only)

`title` and `titleplate_id` are version-scoped and returned on BAID, but **no
green/blue/red play result carries a title-equip field** — the cabinet never sends
one. They are therefore display values, settable only out-of-band (e.g. a future web
page), and default to empty per version.

## Colours

Body colours (`color_face/body/limb`) are **not** cosmetics in this sense: they have
no cabinet setter, are version-agnostic, and are managed by the web customise page on
the shared `players` row.
