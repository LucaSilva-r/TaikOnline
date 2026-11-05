// Relays Waddamburo's online play between the two players of a room who can't reach each other directly.
//
// A game connects with ?ticket=..., which TaikOnline signed for one member of one room (WaddamburoRooms::relayTicket).
// The relay pairs the two members of a room and passes each one's binary frames to the other, as they are: the game's
// own peer-link packets. It keeps nothing and never talks to TaikOnline: the shared secret is all it needs. Sitting
// behind the Cloudflare tunnel, it never shows the server's address. It logs each connection's room and member, how it
// ended and how many frames it passed (to tell a relayed game's trouble from the peer-to-peer link's), never an
// address or a frame's content.

import { createHmac, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';
import { WebSocketServer } from 'ws';

// A peer-link packet is at most ~1.2 kB; anything bigger isn't one of ours.
const MAX_FRAME_BYTES = 2048;
const HEARTBEAT_MS = 30_000;

/** The room and member a ticket was signed for, or null when it's forged, malformed or expired. */
export function verifyTicket(ticket, secret, nowSeconds = Date.now() / 1000) {
    if (typeof ticket !== 'string' || !ticket.includes('.')) {
        return null;
    }
    const [payload, signature] = ticket.split('.', 2);
    const expected = createHmac('sha256', secret).update(payload).digest();
    const given = Buffer.from(signature, 'base64url');
    if (given.length !== expected.length || !timingSafeEqual(given, expected)) {
        return null;
    }
    const [room, member, expires] = Buffer.from(payload, 'base64url').toString('utf8').split(':');
    if (!room || !member || !(Number(expires) > nowSeconds)) {
        return null;
    }
    return { room, member };
}

const timestamped = (line) => console.log(`${new Date().toISOString()} ${line}`);

/** Starts the relay on `port` (0: any free one); `log` takes one line per event. Returns the HTTP server, listening. */
export function startRelay({ port, secret, log = timestamped }) {
    if (!secret) {
        throw new Error('RELAY_SECRET is not set.');
    }
    const rooms = new Map(); // room code -> Map(member -> socket)
    const server = createServer((request, response) => {
        // For the container's health check; everything else is the WebSocket upgrade.
        response.writeHead(request.url === '/health' ? 200 : 404).end();
    });
    const sockets = new WebSocketServer({ server, maxPayload: MAX_FRAME_BYTES });

    sockets.on('connection', (socket, request) => {
        const ticket = verifyTicket(new URL(request.url, 'http://relay').searchParams.get('ticket'), secret);
        if (ticket === null) {
            log('rejected: bad or expired ticket');
            socket.close(4001, 'bad ticket');
            return;
        }
        const { room: code, member } = ticket;
        const who = `room ${code} member ${member}`;
        const room = rooms.get(code) ?? new Map();
        if (!room.has(member) && room.size >= 2) {
            log(`${who} rejected: room full`);
            socket.close(4003, 'room full');
            return;
        }
        // The same member again (their game reconnected): the new connection replaces the old one.
        const previous = room.get(member);
        if (previous) {
            previous.replaced = true;
            previous.terminate();
        }
        room.set(member, socket);
        rooms.set(code, room);
        const opened = Date.now();
        socket.sent = 0;
        socket.received = 0;
        log(`${who} connected${previous ? ' (replacing its previous connection)' : ''}, ${room.size} of 2 in the room`);

        // A bad frame (too big, malformed) closes this connection; unhandled, it would end the whole relay.
        socket.on('error', (error) => {
            log(`${who} error: ${error.message}`);
            socket.terminate();
        });
        socket.isAlive = true;
        socket.on('pong', () => {
            socket.isAlive = true;
        });
        socket.on('message', (data, isBinary) => {
            if (!isBinary) {
                return;
            }
            socket.received++;
            for (const [other, peer] of room) {
                if (other !== member && peer.readyState === peer.OPEN) {
                    peer.send(data, { binary: true });
                    socket.sent++;
                }
            }
        });
        socket.on('close', (closeCode, reason) => {
            const how = socket.timedOut ? 'timed out (no pong)' : socket.replaced ? 'replaced' : `closed ${closeCode}${reason?.length ? ` ${reason}` : ''}`;
            log(`${who} ${how} after ${((Date.now() - opened) / 1000).toFixed(1)} s, ${socket.received} frames in, ${socket.sent} passed on`);
            if (room.get(member) === socket) {
                room.delete(member);
            }
            if (room.size === 0 && rooms.get(code) === room) {
                rooms.delete(code);
            }
        });
    });

    // A game that vanished without closing stops holding its place.
    const heartbeat = setInterval(() => {
        for (const socket of sockets.clients) {
            if (!socket.isAlive) {
                socket.timedOut = true;
                socket.terminate();
                continue;
            }
            socket.isAlive = false;
            socket.ping();
        }
    }, HEARTBEAT_MS);
    server.on('close', () => clearInterval(heartbeat));

    server.listen(port);
    return server;
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = startRelay({ port: Number(process.env.PORT ?? 8080), secret: process.env.RELAY_SECRET });
    server.on('listening', () => console.log(`Relay listening on ${server.address().port}.`));
}
