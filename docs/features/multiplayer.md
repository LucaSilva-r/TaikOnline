# Waddamburo multiplayer

Planned: online play between two home players, each alone at their PC. Arcade mode is excluded. Gameplay
traffic goes peer to peer with NAT punchthrough, so TaikOnline is involved as little as possible. Scores from
these plays are unranked and never uploaded.

Player status, rooms and playing a song together work (tested between Italy and Brazil, 2026-11-02). The game side
lives in the Waddamburo repo; see Playing below.

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

## Rooms

A room introduces two players, and that's all the server does for them.

- **Lifecycle:** `POST api/wdb/rooms` opens one, named after its host unless given a name, and public unless told
  otherwise. `POST api/wdb/rooms/{code}/join` takes the one guest seat, with its password if it has one. The host
  changes settings with `PATCH api/wdb/rooms/{code}`. `DELETE api/wdb/rooms/{code}` closes the room for its host and
  frees the seat for its guest. Rooms live in the `status` cache store, and go 45 s after the games stop calling
  `POST api/wdb/rooms/{code}/keep-alive`.
- **Finding one:** `GET api/wdb/rooms` lists public rooms with a free seat. Other rooms are joined by code.
  `POST api/wdb/rooms/quick-play` takes the waiting player's room, or starts waiting in a new one. A quick play room is
  its own kind (`is_quick`): never listed, changed or joined by code, so quick play alone fills it.
- **Names:** other players see a room's name, so it's one line and 32 characters at most. A muted player's rooms are
  always named after them.
- **Who:** home players with a player who aren't banned. Being Invisible doesn't stop anyone joining by code.
- **Signalling:** `presence-wdb.room.{code}`, for its members' games only. They exchange connection details in
  client events, which Reverb relays without Laravel seeing them. Treat their contents as untrusted.
- **Connecting:** `GET api/wdb/rooms/{code}/ice-servers` gives members this instance's coturn (the `turn` service) for
  STUN, and TURN credentials signed with `TURN_SECRET` that last as long as a room. Without `TURN_HOST` they get a
  public STUN server and no relay.
- **The link:** the game's own `PeerLink` (C#, no native library) probes every address both players gathered, prefers
  a route without the relay. It speaks UDP over IPv4 and IPv6; STUN and the relay are IPv4 only. A network that blocks
  UDP can't connect.
- **Testing a network:** `--online-test` opens a room and `--online-test=CODE` joins one, with no game window, and
  reports the route and round trip.

## Playing

All in the game: the server only introduced the players.

- **Choosing:** the host picks a song and course and holds on the rainbow's title. The guest's Song Select reloads on
  that song alone and they pick their course. A guest finds their own copy of a custom song by its chart's id (a hash of
  the file), wherever their library keeps it.
- **Sending a song:** a guest without the host's TJA or osu! song gets it over the peer link: every course's parsed
  chart and the original audio, 64 MB at most. The first time, the host confirms they're authorized to share their
  custom songs' charts and audio (`share_warning_accepted` in config.cfg). Received songs live in memory only and go
  when the game closes. The game's own songs (Stock, Nijiiro) are never sent: without one, the host plays alone.
- **Starting:** both load the song with the other player's chart and options (the host's seed) on the second lane, and
  hold on the title until both could open it. The host waits half a round trip after "go", so both open together.
- **The other lane:** their hits arrive over the peer link and drive their lane a little more than half the round trip
  behind (120 to 400 ms), so each lands where they hit it. Lanes don't share hand notes.
- **Charts:** both play the one-player charts. A kusudama is each player's own: on the other player's lane it's a balloon
  with the same hits, so the two games score that note a little differently.
- **Two players:** the song and its results run as two players at the drums do (shutter, Don-chans, both results).
  Song Select stays one player's, with the partner shown beside them.
- **No pausing, no saving:** leaving the window doesn't stop an online song, Escape opens the menu over it, and
  nothing played online is kept or uploaded.
- **IP warning:** the first time the lobby opens, it says the other player sees your IP address; accepting is kept in
  config.cfg (`online_warning_accepted`).
- **Still to do:** a relay over TCP for networks that block UDP: coturn's TLS
  listener on 443 (a certificate and a DNS-only record), TURN over a stream in `PeerLink`, tried after direct and the UDP
  relay, and a `turns:` server from `ice-servers`. Hits would stall behind a lost packet, but the delayed lane hides
  most of that. Worth it once a tester can't connect over UDP.

## Before deploying

Nothing here is deployed yet: it all goes out together once multiplayer is ready.

- Add the `redis` (Valkey) service from `docker/production/compose.yaml` to the host's `compose.yaml` by hand. Don't
  copy the whole file over, because the host's differs.
- Set `REDIS_HOST=redis` in the host's `.env`.
- Add the `turn` service too, and set `TURN_HOST`, `TURN_SECRET` and `TURN_EXTERNAL_IP` in the host's `.env`. Point
  `TURN_HOST` at the VPS with a DNS-only record, since the Cloudflare tunnel only carries HTTP, and open 3478/udp and
  49160-49260/udp on its firewall. This makes the VPS's IP public.
- Update the host's `storage/legal/privacy.md` from `resources/legal/privacy.md`, and decide whether to bump
  `LEGAL_VERSION`, which makes everyone accept again.
- Say in the Privacy Policy that playing online shows your IP address to the other player, including strangers in
  public rooms and Quick Play, which is how a peer-to-peer connection works.
- The game warns players about that the first time they open the online lobby.
