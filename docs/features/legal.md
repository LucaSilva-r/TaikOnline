# Rules and Privacy Policy

Each instance writes its own: `storage/legal/rules.md` and `privacy.md` (`LEGAL_PATH` overrides the folder), starting from the templates in `resources/legal/` and replacing their `[PLACEHOLDERS]`. They are not committed, as they name whoever runs that instance. They show at `{version}/rules` and `/privacy`, linked from the footer.

`LEGAL_VERSION` in `.env` is the version of the Rules players must accept (`config/legal.php`). Unset or without the files, nothing is asked and the pages are off. A new value asks everyone again, so change it only for changes that matter.

- **Website:** a logged-in player who has not accepted that version is sent to the Rules on every request (`EnsureRulesAccepted`) and can accept, log out, read the documents or delete their account. Sign-up asks too. `users.rules_accepted_version` and `rules_accepted_at` record it.
- **Waddamburo:** uploads by a home player's token (plays, dan runs, charts) get a 403 until they accept, and they get one `rules_required` notice per version (`EnsureWaddamburoRulesAccepted`). The client keeps a play until an upload succeeds, so they upload once accepted.
- **Not asked:** cabinets, which have no account on the machine; card play on original game versions; BAIDs without an account.

There are deliberately no Terms of Service for now.

## What changed

Each version's documents are copied, the first time that version is in force, to `storage/app/legal-history/{version}/` (`LEGAL_HISTORY_PATH` overrides; versions named `[A-Za-z0-9._-]` only). Both pages offer "Show what changed since" any earlier kept version: paragraph by paragraph (blocks between blank lines), added ones in green, removed ones struck through in red. A player asked to accept a new version sees the changes since the one they accepted, when it was kept.

So history starts with the version in force when this shipped: deploy it, let any signed-in request (or a page view) keep the current version, and only then edit the documents and bump `LEGAL_VERSION`.
