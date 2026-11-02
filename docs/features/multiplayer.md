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
- **Connecting:** `GET api/wdb/rooms/{code}/ice-servers` gives members a public STUN server (Cloudflare's, which only
  learns the players' own addresses) and, when `RELAY_URL` is set, the relay with a ticket signed with `RELAY_SECRET`
  for that member of that room, good as long as a room lasts.
- **The relay** (`relay/`, the `relay` service): a small Node WebSocket server on its own hostname through the Cloudflare
  tunnel, so the server's address stays hidden. It checks the ticket's signature with the same secret, pairs a room's
  two members and passes each one's binary frames to the other, untouched. It keeps and logs nothing and never calls
  TaikOnline. `npm test` in `relay/` covers it.
- **The link:** the game's own `PeerLink` (C#, no native library) probes every address both players gathered over UDP
  (IPv4 and IPv6), and the relay at the same time; a direct route replaces the relay as soon as one answers. The relay
  goes over TCP, so it gets through networks that block UDP, at the cost of more latency and a stall after a lost
  packet, which the delayed lane mostly hides.
- **Testing a network:** `--online-test` opens a room and `--online-test=CODE` joins one, with no game window, and
  reports the route and round trip. `WADDAMBURO_ONLINE_RELAY_ONLY=1` connects through the relay alone.

## Playing

All in the game: the server only introduced the players.

- **Choosing:** whoever's turn it is picks a song and course and holds on the rainbow's title; the other player waits in
  the lobby ("X is choosing a song...", only Leave), then their Song Select reloads on that song alone and they pick
  their course. Quick Play takes turns; a hosted room's host sets "Songs: Host chooses / Guest chooses / Take turns"
  (default Host). The host's game keeps the turn and tells the guest (`turn` message); a new guest starts with the host.
  The other player finds their own copy of a custom song by its chart's id (a hash of the file), wherever their library
  keeps it. When they don't have a game song (Stock, Nijiiro), the chooser is sent back to pick another.
- **Sending a song:** a player without the chooser's TJA or osu! song gets it over the peer link: every course's parsed
  chart and the original audio, 64 MB at most. The first time, the chooser confirms they're authorized to share their
  custom songs' charts and audio (`share_warning_accepted` in config.cfg). Received songs live in memory only and go
  when the game closes. The game's own songs (Stock, Nijiiro) are never sent.
- **Starting:** both load the song with the other player's chart and options (the host's seed) on the second lane, and
  hold on the title until both could open it. The host waits half a round trip after "go", so both open together.
- **The other lane:** it runs in time with this player's song, whatever the delay. Their hits arrive over the peer link
  (each packet carries every hit not yet confirmed, with its time; through the relay every 50 ms instead of every tick)
  and a shadow of their lane judges them at the times they were hit, so its score, combo, gauge and results are exactly
  theirs, just late. Each note reaching the line takes the next judgement the shadow has made, misses included; with
  none yet (the first notes, or a lag spike) it's a placeholder: it pops, with no judgement, score or drum sound. So the
  lane runs a few notes behind in judgements, never in time. Their hits that judged no note (stray ones, rolls) flash
  their drum as they arrive, with no sound. Their clear/fail banner waits for their play to catch up, 3 s at most. Once
  a player's notes are over their game sends its own result, which the other's results screen shows as it is. Lanes
  don't share hand notes.
- **Leaving:** the game that leaves says "bye" over the link, so the other reacts at once; the server's room going, the
  link going quiet and the channel's presence cover a crash. F5 shows the link's round trip and whether it's relayed.
- **No pausing, no saving:** leaving the window doesn't stop an online song, Escape opens the menu over it, and
  nothing played online is kept or uploaded.
- **IP warning:** the first time the lobby opens, it says the other player sees your IP address; accepting is kept in
  config.cfg (`online_warning_accepted`).

## Before deploying

Nothing here is deployed yet: it all goes out together once multiplayer is ready.

- Add the `redis` (Valkey) service from `docker/production/compose.yaml` to the host's `compose.yaml` by hand. Don't
  copy the whole file over, because the host's differs.
- Set `REDIS_HOST=redis` in the host's `.env`.
- Add the `relay` service too (it builds from `relay/`), set the same random `RELAY_SECRET` for it and Laravel, and
  `RELAY_URL=wss://relay.taikonline.com`. In the tunnel, give that hostname the service `http://relay:8080`. Nothing
  needs a DNS-only record or an open port, so the VPS's address stays hidden.
- Update the host's `storage/legal/privacy.md` from `resources/legal/privacy.md`, and decide whether to bump
  `LEGAL_VERSION`, which makes everyone accept again.
- Say in the Privacy Policy that playing online shows your IP address to the other player, including strangers in
  public rooms and Quick Play, which is how a peer-to-peer connection works.
- The game warns players about that the first time they open the online lobby.
