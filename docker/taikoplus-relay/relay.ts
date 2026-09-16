// Taiko+ online 1v1 relay: lobby rooms, start deadline, clock pings, and a
// fallback path for gameplay hits when the clients cannot reach each other
// over UDP. Rooms live in memory; a restart drops every room.
//
// Clients authenticate with a ticket from POST /api/taikoplus/ticket:
// base64url(json {cab, exp}) + "." + base64url(hmac-sha256(secret, payload)).
import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto';
import type { Server, ServerWebSocket } from 'bun';

type Song = { music_id: string; chart: string; audio: string };
type Client = {
    id: string;
    name: string;
    cabinet: string;
    room: Room | null;
    course: number;
    ready: boolean;
};
type Socket = ServerWebSocket<Client>;
type Room = {
    code: string;
    owner: Socket;
    members: Socket[];
    song: Song | null;
    phase: 'lobby' | 'launching' | 'playing';
    loaded: Set<Socket>;
    timer: ReturnType<typeof setTimeout> | null;
};

const LOAD_TIMEOUT_MS = 30_000;
const START_LEAD_MS = 1500;
const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

export function verifyTicket(secret: string, ticket: unknown, now = Date.now()): string | null {
    if (typeof ticket !== 'string') return null;
    const [payload, mac] = ticket.split('.');
    if (!payload || !mac) return null;
    const expected = createHmac('sha256', secret).update(payload).digest();
    const given = Buffer.from(mac, 'base64url');
    if (given.length !== expected.length || !timingSafeEqual(given, expected)) return null;
    try {
        const claims = JSON.parse(Buffer.from(payload, 'base64url').toString());
        if (typeof claims.cab !== 'string' || typeof claims.exp !== 'number') return null;
        return claims.exp * 1000 >= now ? claims.cab : null;
    } catch {
        return null;
    }
}

export function startRelay(secret: string, port: number): Server {
    const rooms = new Map<string, Room>();
    let nextId = 1;

    const send = (ws: Socket, message: object) => ws.send(JSON.stringify(message));
    const broadcast = (room: Room, message: object) => room.members.forEach((m) => send(m, message));
    const others = (ws: Socket, message: object) =>
        ws.data.room?.members.forEach((m) => m !== ws && send(m, { ...message, from: ws.data.id }));

    const publishRoom = (room: Room) =>
        broadcast(room, {
            type: 'room',
            code: room.code,
            owner: room.owner.data.id,
            phase: room.phase,
            song: room.song,
            members: room.members.map(({ data }) => ({
                id: data.id,
                name: data.name,
                course: data.course,
                ready: data.ready,
            })),
        });

    const toLobby = (room: Room) => {
        if (room.timer) clearTimeout(room.timer);
        room.timer = null;
        room.phase = 'lobby';
        room.loaded.clear();
        room.members.forEach((m) => (m.data.ready = false));
    };

    const leave = (ws: Socket) => {
        const room = ws.data.room;
        if (!room) return;
        ws.data.room = null;
        ws.data.ready = false;
        room.members = room.members.filter((m) => m !== ws);
        if (room.members.length === 0) {
            toLobby(room);
            rooms.delete(room.code);
            return;
        }
        if (room.owner === ws) room.owner = room.members[0];
        broadcast(room, { type: 'peer_left', id: ws.data.id });
        // A match cannot continue into a new round without the opponent; the
        // survivor keeps playing its current song locally.
        toLobby(room);
        publishRoom(room);
    };

    const newCode = () => {
        for (;;) {
            const code = Array.from(randomBytes(5), (b) => CODE_ALPHABET[b % CODE_ALPHABET.length]).join('');
            if (!rooms.has(code)) return code;
        }
    };

    const handlers: Record<string, (ws: Socket, m: any) => void> = {
        create(ws) {
            leave(ws);
            const room: Room = {
                code: newCode(),
                owner: ws,
                members: [ws],
                song: null,
                phase: 'lobby',
                loaded: new Set(),
                timer: null,
            };
            rooms.set(room.code, room);
            ws.data.room = room;
            publishRoom(room);
        },
        join(ws, m) {
            const room = rooms.get(String(m.code ?? '').toUpperCase());
            if (!room) return send(ws, { type: 'error', error: 'no_such_room' });
            if (room.members.length >= 2) return send(ws, { type: 'error', error: 'room_full' });
            leave(ws);
            room.members.push(ws);
            ws.data.room = room;
            toLobby(room);
            publishRoom(room);
        },
        leave(ws) {
            leave(ws);
        },
        select(ws, m) {
            const room = ws.data.room;
            if (!room || room.owner !== ws || room.phase !== 'lobby') return;
            const song = { music_id: m.music_id, chart: m.chart, audio: m.audio };
            if (!Object.values(song).every((v) => typeof v === 'string' && v.length > 0 && v.length <= 128)) return;
            room.song = song;
            room.members.forEach((member) => (member.data.ready = false));
            publishRoom(room);
        },
        ready(ws, m) {
            const room = ws.data.room;
            if (!room || room.phase !== 'lobby' || !room.song) return;
            if (!Number.isInteger(m.course) || m.course < 0 || m.course > 4) return;
            ws.data.course = m.course;
            ws.data.ready = true;
            if (room.members.length === 2 && room.members.every((member) => member.data.ready)) {
                room.phase = 'launching';
                room.timer = setTimeout(() => {
                    broadcast(room, { type: 'abort', reason: 'load_timeout' });
                    toLobby(room);
                    publishRoom(room);
                }, LOAD_TIMEOUT_MS);
                publishRoom(room);
                broadcast(room, { type: 'launch', key: randomBytes(32).toString('base64') });
                return;
            }
            publishRoom(room);
        },
        unready(ws) {
            const room = ws.data.room;
            if (!room || room.phase !== 'lobby') return;
            ws.data.ready = false;
            publishRoom(room);
        },
        loaded(ws) {
            const room = ws.data.room;
            if (!room || room.phase !== 'launching') return;
            room.loaded.add(ws);
            if (room.loaded.size < room.members.length) return;
            if (room.timer) clearTimeout(room.timer);
            room.timer = null;
            room.phase = 'playing';
            broadcast(room, { type: 'start', deadline: Date.now() + START_LEAD_MS });
        },
        // Song over (or quit): the room returns to its lobby once everyone is back.
        done(ws) {
            const room = ws.data.room;
            if (!room || room.phase === 'lobby') return;
            ws.data.ready = false;
            room.loaded.delete(ws);
            if (room.members.every((member) => !member.data.ready)) {
                toLobby(room);
                publishRoom(room);
            }
        },
        hit(ws, m) {
            if (ws.data.room?.phase === 'playing') others(ws, { type: 'hit', hits: m.hits });
        },
        final(ws, m) {
            if (ws.data.room && ws.data.room.phase !== 'lobby') others(ws, { type: 'final', totals: m.totals });
        },
        cand(ws, m) {
            if (ws.data.room && ws.data.room.phase !== 'lobby') others(ws, { type: 'cand', addrs: m.addrs });
        },
    };

    return Bun.serve<Client, {}>({
        port,
        fetch(request, server) {
            if (new URL(request.url).pathname !== '/taikoplus/ws') return new Response('Not Found', { status: 404 });
            const data: Client = { id: '', name: '', cabinet: '', room: null, course: 0, ready: false };
            return server.upgrade(request, { data }) ? undefined : new Response('Upgrade required', { status: 426 });
        },
        websocket: {
            maxPayloadLength: 16 * 1024,
            idleTimeout: 30,
            message(ws, raw) {
                let m: any;
                try {
                    m = JSON.parse(String(raw));
                } catch {
                    return ws.close(1003, 'bad json');
                }
                if (m?.type === 'ping') return send(ws, { type: 'pong', c: m.c, s: Date.now() });
                if (!ws.data.id) {
                    const cabinet = m?.type === 'hello' ? verifyTicket(secret, m.ticket) : null;
                    if (!cabinet) return ws.close(4001, 'unauthorized');
                    ws.data.id = String(nextId++);
                    ws.data.cabinet = cabinet;
                    ws.data.name = String(m.name ?? 'Guest').slice(0, 32) || 'Guest';
                    return send(ws, { type: 'welcome', id: ws.data.id });
                }
                handlers[m?.type]?.(ws, m);
            },
            close(ws) {
                leave(ws);
            },
        },
    });
}

if (import.meta.main) {
    const secret = process.env.TAIKOPLUS_RELAY_SECRET;
    if (!secret) throw new Error('TAIKOPLUS_RELAY_SECRET is not set');
    const server = startRelay(secret, Number(process.env.PORT ?? 8090));
    console.log(`taikoplus relay on :${server.port}`);
}
