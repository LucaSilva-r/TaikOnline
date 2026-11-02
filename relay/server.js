// Relays Waddamburo's online play between the two players of a room who can't reach each other directly.
//
// A game connects with ?ticket=..., which TaikOnline signed for one member of one room (WaddamburoRooms::relayTicket).
// The relay pairs the two members of a room and passes each one's binary frames to the other, as they are: the game's
// own peer-link packets. It keeps nothing, logs no traffic, and never talks to TaikOnline: the shared secret is all
// it needs. Sitting behind the Cloudflare tunnel, it never shows the server's address.

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

/** Starts the relay on `port` (0: any free one). Returns the HTTP server, listening. */
export function startRelay({ port, secret }) {
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
            socket.close(4001, 'bad ticket');
            return;
        }
        const { room: code, member } = ticket;
        const room = rooms.get(code) ?? new Map();
        if (!room.has(member) && room.size >= 2) {
            socket.close(4003, 'room full');
            return;
        }
        // The same member again (their game reconnected): the new connection replaces the old one.
        room.get(member)?.terminate();
        room.set(member, socket);
        rooms.set(code, room);

        // A bad frame (too big, malformed) closes this connection; unhandled, it would end the whole relay.
        socket.on('error', () => socket.terminate());
        socket.isAlive = true;
        socket.on('pong', () => {
            socket.isAlive = true;
        });
        socket.on('message', (data, isBinary) => {
            if (!isBinary) {
                return;
            }
            for (const [other, peer] of room) {
                if (other !== member && peer.readyState === peer.OPEN) {
                    peer.send(data, { binary: true });
                }
            }
        });
        socket.on('close', () => {
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
