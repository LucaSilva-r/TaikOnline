# Moderation

Status: step 1 of **Order** built, step 2 mostly built (2026-10-28); steps 3–5 are still design.

Waddamburo is open source, so the client cannot be trusted: a modified build can send any replay
it likes. The server already rescores every play from its replay with the Waddamburo binary
(`WaddamburoScorer`, `--rescore`), so a score always follows the real rules, but nothing stops a
replay from being produced by a macro or an autoplay tool. Moderation is the answer on the server:
staff who can review plays and act on them, with tools to do it well.

## Roles

`UserRole` gains `Moderator` (`admin` > `moderator` > `user`).

- One gate, `moderate`: admin or moderator.
- New `mod/` route group behind `can:moderate` for the moderation pages; `admin/` stays admin-only.
- Moderators: hide/restore plays, shadowban, timed bans, mutes, handle reports and the review queue.
- Admin-only: role changes, permanent bans, sanctions on staff (a moderator cannot act on another
  moderator or an admin).

## Sanctions

Table `sanctions`:

| column | meaning |
|---|---|
| `baid` | the sanctioned player (plays, cabinets and Waddamburo identity all go by baid) |
| `type` | `ban`, `shadowban`, `mute` |
| `reason` | staff-only text |
| `note_to_player` | optional text the player is shown (bans, mutes) |
| `expires_at` | null = permanent |
| `created_by`, `revoked_at`, `revoked_by` | who and when |

Rows are never deleted: revoking keeps the history. A player is sanctioned while a row of that
type is unrevoked and unexpired.

- **Shadowban**: uploads are accepted and the player sees their plays as usual, including their
  own leaderboard position. Everyone else sees nothing.
- **Ban**: uploads are refused with a clear message; web and cabinet logins are blocked.
- **Mute**: stored now, enforced when forum and chat exist. A web user maps to their baid.

## Hiding plays

`wdb_plays` gains `hidden_at`, `hidden_by`, `hidden_reason`: one play removed from every board
without sanctioning the player. Replaces hard deletes for moderation, so every removal can be
undone and has an owner.

## Visibility

One scope, `WdbPlay::visibleTo(?int $viewerBaid)`, drops hidden plays and shadowbanned players'
plays unless the viewer owns them. Every place that shows Waddamburo scores goes through it:
`WaddamburoScoreController::scores`, `WaddamburoRankAggregateService`, the board and community
pages. Rank aggregates are recomputed for the player when a sanction is added, revoked or
expires, and for the chart when a play is hidden or restored. A feature test asserts that a
shadowbanned or hidden play reaches no board seen by another player.

## Action log

Table `moderation_actions`: actor, action, target (baid or play), reason, time, and the related
sanction or report. Every moderation write goes through it. Admins see the whole log; moderators
see the history of the player they are looking at.

## Reports and review queue

- Logged-in players can report a play (rate limited, one open report per play and reporter).
- One review queue holds user reports and automatic flags (see below). Each item is closed as
  dismissed, play hidden or player sanctioned; the closing action is logged.
- No action is taken the instant a play is flagged or reported: quiet review, then action in
  batches, so the timing of a removal does not tell a cheater what gave them away.

## Replay inspector

Page `mod/plays/{id}` for checking a play visually (no song audio, the server does not have it).

The judgement timeline comes from the engine, never from a JS reimplementation that could drift:
`--rescore` returns it with every play's result (every note's time, kind, result and offset, and
every input's time, action and what it hit: note, roll, capped roll hit, nothing), and the server
keeps it on the play.

The page shows:
- a lane view with the notes and the player's hits, play/pause, seek, 0.25×–2× speed, frame steps;
- a hit offset plot over the song and its histogram;
- roll hit intervals per hand;
- a synthesized click per input, so the rhythm can be heard without the song;
- the player's sanction history, their other plays on the chart, and the automatic review output.

A trimmed, player-facing version (your own plays only) may come later.

## Automatic review

An optional private analyzer binary, set with `WADDAMBURO_ANALYZER`, reads the same input as
`--rescore` and returns an opaque `analysis` JSON and a `flag` (`none`, `review`, `strong`) per
play, stored on `wdb_plays`. Flagged plays enter the review queue; nothing is sanctioned
automatically. The analyzer is not part of this repository or the Docker image; it is mounted on
the production host by hand. When the variable is unset, analysis is skipped.

## Implementation (step 1)

- `UserRole::Moderator`, `User::isStaff()`, gate `moderate` (AppServiceProvider). Moderators see only
  the Moderation entry in the backend sidebar.
- `sanctions`, `moderation_actions`, `wdb_plays.hidden_*`: migration `create_moderation_tables`.
  Models `Sanction` (`active` scope, `activeFor()`, `banMessage()`), `ModerationAction`;
  `Player::activeBan()`.
- `ModerationService` does every write and its log entry. `canActOn()`: moderators act on non-staff
  players only, admins on anyone but themselves. `canIssue()`: permanent bans are admin-only
  (moderators pick a duration; shadowbans and mutes can be permanent).
- Visibility: `WdbPlay::visibleTo()` scope (and `WdbPlay::visibility()` for plain queries). Hidden
  plays are hidden from everyone, owner included; shadowbanned players' plays only from others.
  `WaddamburoRankAggregateService::bests()` and `standings()` take the viewer's baid. Standings
  filter shadowbanned players when read, so a sanction needs no recompute (and expiry needs no job);
  hiding or restoring a play recomputes its player's aggregate.
- Bans: Fortify login and Waddamburo login/device poll refuse with `banMessage()`;
  `EnsureNotBanned` (web group) signs a banned user out; `AuthenticateWaddamburo` answers 403
  `{message, banned: true}` to a banned home token; cabinet card lookup answers the same; a
  cabinet's queued plays of a banned player are acknowledged in `accepted` (so the queue drains),
  listed in `refused` with the message, and not stored. Card logins on the real Taiko cabinets
  (Green/legacy protocols) are not blocked yet.
- Pages: `mod/` (player lookup, sanctions in force, whole log for admins) and `mod/players/{baid}`
  (issue/revoke sanctions, hide/restore plays, the player's history). Tests: `tests/Feature/ModerationTest.php`.

## Implementation (step 2)

- Waddamburo-public `--rescore` (branch `inspect-mode`) returns each play's `timeline` beside its
  result: `windows`, `duration`, `notes` (t, kind, hand, result, offset, judged_at), `long_notes`
  (start, end, kind, required, hits), `inputs` (t, action 0–3, hit: note/strong/roll/pop/capped/none,
  note index), `bars`, `gogo`. Times in ms. Production needs a Waddamburo release with it, then
  `app:rescore-waddamburo-plays --all` fills it in for the stored plays.
- `WaddamburoScorer` keeps it gzipped in `wdb_plays.timeline` (~20 KB a play);
  `WdbPlay::judgementTimeline()` reads it.
- Page `{version}/plays/{id}` (`WaddamburoPlayController`, `WaddamburoPlay.svelte`), Waddamburo only.
  Song boards, song recent plays, board recent plays and the mod player page link to it. Everyone
  sees the result, placement, other plays on the chart, and from the timeline only mean offset,
  early/late counts and drumroll speed (`publicStats()`). Staff also see the moderation panel
  (hide/restore, link to the player's mod page) and the timeline plots (`PlayInspection.svelte`):
  hit timing over the song with misses and hits on no note, Good rate over the song, offset
  histogram, drumroll hit intervals per hand, σ / unstable rate / ghost hits / capped roll hits /
  hand balance. Staff open hidden and shadowbanned plays; others get 404.
- Boards give staff a Moderate button (`moderationBaid` prop).
- Replay player (`ReplayPlayer.svelte`, everyone): a canvas lane with the notes as the engine judged
  them (hit notes leave, missed ones scroll on), rolls, balloons and kusudama with their hits left,
  go-go tint, Good/Ok/Bad, combo and drumroll counters, play/pause, seek, 0.5–2× speed. It gets the
  timeline without inputs (`replay` prop); inputs stay in the staff-only `inspection`.
- Not built yet: synthesized clicks per input, frame stepping.

## Order

1. Role and gate, sanctions, play hiding, visibility scope, action log, moderation pages (player
   lookup, sanctions, plays).
2. Judgement timeline from `--rescore` and the replay inspector page.
3. Reports and the review queue.
4. Analyzer hook.
5. Mute enforcement, with forum and chat.

## Open questions

- Sanctions on the baid (proposed) or on the web account? Built on the baid.
- Can moderators issue permanent bans, or only timed ones? Built as timed only.
- Do moderators see the analyzer output in the inspector, or admins only?
