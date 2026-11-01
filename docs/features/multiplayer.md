# Waddamburo multiplayer

Planned: online play between two home players, each alone at their PC. Arcade mode is excluded. Gameplay
traffic goes peer to peer with NAT punchthrough, so TaikOnline is involved as little as possible. Scores from
these plays are unranked and never uploaded.

Only player status exists so far.

## Player status

The Waddamburo board shows a green ring for online or playing and an orange one for AFK, with a line of text
such as "Playing 千本桜 (Oni)".

- **Source:** the game sends `PUT api/wdb/status` when the status changes and every 30 s, and `DELETE` when it
  closes. It goes over HTTP because Laravel can't see Reverb's client events and Reverb has no webhooks.
- **AFK:** no key or drum input for 5 minutes outside a song.
- **Storage:** the `status` cache store on the `redis` service (Valkey), with a 90 s TTL and no persistence. If
  Valkey is down, players just show as offline. The board also goes offline by itself after 90 s of silence.
- **Live updates:** `WdbPlayerStatusChanged` on the public channel `wdb.status.{userId}`.
- **Who knows:** only the server. No Reverb channel lists who is online, because even a per-user presence channel
  wouldn't tell Laravel about joins and leaves. A crashed game therefore takes up to 90 s to show offline.
- **Invisible** (the status dropdown in the user menu, immediate): the server keeps the status but nobody else
  sees it. Stored as `users.presence_status` (`App\Enums\PresenceStatus`).
- **Who isn't kept at all:** banned players and players who haven't accepted the current Rules.
- **Song names:** ranked, legacy and osu! charts are named. Other custom charts, and charts the server doesn't
  know yet, show "Playing a song", because custom charts stay with their communities (`WdbChart::visibleTo`).

## Before deploying

Nothing here is deployed yet: it all goes out together once multiplayer is ready.

- Add the `redis` (Valkey) service from `docker/production/compose.yaml` to the host's `compose.yaml` by hand. Don't
  copy the whole file over, because the host's differs.
- Set `REDIS_HOST=redis` in the host's `.env`.
- Update the host's `storage/legal/privacy.md` from `resources/legal/privacy.md`, and decide whether to bump
  `LEGAL_VERSION`, which makes everyone accept again.
