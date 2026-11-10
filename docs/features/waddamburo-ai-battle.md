# Waddamburo AI battles

Each online battle starts with an authenticated `POST /api/wdb/ai/battles`.
`WaddamburoAiBattles` freezes the seed, version, chart/options/support identity,
and prior verified timing. An unplayed setup is reused for the same selection;
once uploaded it is bound to that play ID and player. Home tokens cannot select
another BAID; cabinet tokens use their existing player-upload permissions.

All AI plays and replays are retained, including losses and failed gauge clears.
Failed normal plays and Dan runs remain local. AI ordinary scores use the same
scoring and leaderboards as normal plays.

`WaddamburoScorer` sends trusted setup context to the game's `--rescore` process.
The shared simulator returns sections, timing and counts; client claims do not
award anything. A missing setup, older client or incompatible simulator leaves
the battle unverified while retaining its ordinary score. Derived support charts
must match the approved base's deterministic simplification.

Only verified battles whose base was approved when the setup was issued count
for progression. The base must still be approved when verification runs. Support
wins and wins without a gauge clear count toward the win total, but rank points
require the standard chart, a win, a clear, and the current rank's star threshold.
First-win and high-level bonuses follow the Green ladder. Awards are recalculated
in setup sequence order after delayed uploads, rescores, and moderation; hidden
and restricted plays are excluded. Synced `ai_plays` contains only verified,
eligible results. The play page shows the checked sections, points and progress;
unverified claims are labelled and do not appear as wins.

## Rollout

Deploy the migration adding `wdb_ai_sessions` and verification columns before the
API code. Deploy the matching game scorer and client together. Run the existing
`php artisan app:rescore-waddamburo-plays --all` when updating scoring rules.
Old AI plays have no trusted setup and cannot retroactively earn online awards.
An older scorer can still rescore ordinary scores, but cannot verify AI battles.
The model is version 1 (AI-don plays every note a 良 until the player's first 可 or 不可).
It is unreleased and changes freely; after release, model changes must add a version and
retain older ones so stored sessions remain reproducible.

Apply the additive schema migration with the normal deployment migration step
(`php artisan migrate --force`).

## Pending

The rank 10+ challenge folder and actual reward grants (titles, songs, costumes)
are not implemented. This change keeps verified win totals for that work.
