# Player state

A card maps to one player identity that is shared across every version, but much of
a player's progress and appearance is **version-specific**: the same numeric id
means a different song, costume, or title in different releases, and the catalogs
differ in size. State is therefore split between a shared row and version-scoped
rows.

## Shared per card — `players`

Keyed by `baid`. One row per card, regardless of which versions it has played.

- Identity: `mydon_name`, `access_token`, `person_id`, `user_id`
- Body colours: `color_face`, `color_body`, `color_limb` (managed by the web
  customise page, not by any cabinet protocol — no version sends colour changes)
- Counters/medals: `total_credit_count`, `total_*_donmedal`, `total_*_katsumedal`
- Misc: `difficulty_played_*`, `last_played_at`, `favorite_song_numbers`,
  `recent_song_numbers`, `unlocked_song_numbers`

## Version-scoped — `game_version` column

These tables carry a `game_version` column and are unique per
`(baid, game_version, …)`:

- **`song_bests`** — best score, rank, play result, and `best_crown`
  (0 none / 1 clear / 2 gold / 3 dondaful) per `(baid, version, song_no, level)`.
  Drives [crowns](../features/crowns.md) and self-best.
- **`song_play_results`** — every stage played, including stored ghost sections.

## Version-scoped — `player_cosmetics`

One row per `(baid, game_version)`. Holds everything whose id space differs between
versions (see [cosmetics](../features/cosmetics.md)):

- Equipped costume parts: `costume_1` … `costume_5`
- Equipped/last-used display: `title`, `titleplate_id`,
  `default_tone_setting`, `default_option_setting`
- Unlock lists: `unlocked_costumes` (a `{slot => [ids]}` map),
  `unlocked_tones`, `unlocked_titles`

`PlayerCosmetic::resolve($baid, $version)` returns the row (or a fresh, unsaved
instance when the player has never played that version), so a new version starts
from clean defaults.

## The rule

> Anything whose id meaning depends on the version belongs in a version-scoped
> table, never on `players`.

Future per-version state (dan progress flags, equipped title once a setter is found,
per-version favourites) should follow the `player_cosmetics` pattern rather than
landing on the shared row.
