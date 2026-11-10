# Waddamburo cabinet tokens

A cabinet logs in to Waddamburo's API with a bearer token (`cabinet_token` in its `config.cfg`);
its players then log in on it with a 6-digit pairing code. Tokens live in `wdb_cabinets`
(`App\Models\WdbCabinet`); only the sha256 hash is stored, the token is shown once when issued.

## Who issues them

- **Admins**: `admin/waddamburo-cabinets` (`user_id` null, shown as "staff").
- **Players**: `settings/cabinets` (`Settings\CabinetTokenController`, `user_id` = owner). Before the
  first token, a fullscreen terms screen must be accepted (`users.cabinet_terms_accepted_at`):
  arcade mode is for fun and recreating the original arcade, making money with it is strictly
  forbidden (highlighted in red); one token per cabinet, never shared, tokens can be terminated for any reason, TaikOnline and
  Waddamburo take no responsibility for misuse. Without accepting, `store` returns 403.
  Owners can revoke their own tokens. Deleting the account deletes its tokens (FK cascade).

## When a token works

`WdbCabinet::forToken()` accepts a token only when it is not revoked (`revoked_at`, permanent),
not disabled (`disabled_at`, admin toggle) and its owner has no active ban. The ban check is live,
so lifting the ban turns the owner's tokens back on. Banned players cannot issue new tokens.

The admin page lists every token ever issued with its owner (banned owners flagged), and lets
admins disable/enable or revoke any of them. Plays keep their `wdb_cabinet_id` either way.

## Translations

The terms screen is the first translated page (English, 日本語, 简体中文, 繁體中文). It uses
Paraglide JS: messages in `resources/js/i18n/messages/{locale}.json`, config in
`project.inlang/settings.json`, compiled by the Vite plugin to `resources/js/paraglide` (ignored).
Locale = saved choice (localStorage), else the browser's languages (`negotiateLocale` in
`resources/js/lib/i18n.ts` maps zh-TW/HK/MO to 繁體), else English. The switcher saves the choice
and reloads.
