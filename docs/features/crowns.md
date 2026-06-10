# Crowns

Crowns are the clear/full-combo medals shown on each song in the selection wheel.
The cabinet requests them once per session via `crownsdata.php` and renders a packed
bitfield.

## Data

A crown rank is stored per `(baid, game_version, song_no, level)` on `song_bests`
as `best_crown`:

| value | meaning |
|-------|---------|
| 0 | none |
| 1 | clear |
| 2 | gold (full combo) |
| 3 | dondaful |

The cabinet reports the rank directly in a stage's `play_result`
(1 = clear, 2 = gold, 3 = dondaful). On save, `PlayResultService` raises
`best_crown` **independently of score** — a later, lower-scoring full combo still
upgrades the crown.

## Wire format

`crownsdata.php` returns `hash_crown_flg`: a fixed buffer, **gzip-compressed**.

- One value per `song_no`, packed at bit offset `song_no * 10` (10 bits per song).
- Within a song's 10 bits, two bits per difficulty in order
  easy, normal, hard, oni, ura (`easy << 0 | normal << 2 | … | ura << 8`).
- The 2-bit wire state is: clear → `2`, gold/dondaful → `3`, none → `0`.
- The inflated buffer is 1280 bytes (1024 songs × 10 bits); then gzipped.

This is built by `ScoreMapper::crownFlagBytes()`.

> Encoding note: crown flags are gzipped, but the song-release flags in other
> responses are sent raw. This per-field split is dictated by the cabinet, not a
> convention we chose — see [cosmetics](cosmetics.md) for the raw bitset helper.

## Difficulty mapping

`song_bests.level` matches the cabinet's difficulty numbering 1:1 — Easy 1, Normal 2,
Hard 3, Oni 4, UraOni 5 — so the crown bit slot is `level - 1`.

## Scope

`crownFlagBytes` lives in the shared `ScoreMapper`, and `crownsData` is on the base
`GameHandler`, so every version returns this format. It is verified for Green; Blue
uses the identical crown-state enum. Older dialects (Sorairo–White) are unverified;
if one diverges, override `crownsData` in a version handler.
