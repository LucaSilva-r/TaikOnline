# Moderation

Status: **design** (2026-10-28), nothing implemented yet.

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
Waddamburo gains an `--inspect` mode (same stdin/stdout JSON lines as `--rescore`) that returns
every note (time, kind, result, offset) and every input (time, action, what it hit: note, roll,
capped roll hit, nothing). The server caches the output per play and scoring version.

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

## Order

1. Role and gate, sanctions, play hiding, visibility scope, action log, moderation pages (player
   lookup, sanctions, plays).
2. `--inspect` in Waddamburo and the replay inspector page.
3. Reports and the review queue.
4. Analyzer hook.
5. Mute enforcement, with forum and chat.

## Open questions

- Sanctions on the baid (proposed) or on the web account?
- Can moderators issue permanent bans, or only timed ones?
- Do moderators see the analyzer output in the inspector, or admins only?
