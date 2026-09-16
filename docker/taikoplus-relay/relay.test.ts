import { afterAll, expect, test } from 'bun:test';
import { createHmac } from 'node:crypto';
import { startRelay, verifyTicket } from './relay';

const secret = 'test-secret';
const server = startRelay(secret, 0);
afterAll(() => server.stop(true));

function ticket(cab: string, exp = Date.now() / 1000 + 60): string {
    const payload = Buffer.from(JSON.stringify({ cab, exp })).toString('base64url');
    return `${payload}.${createHmac('sha256', secret).update(payload).digest('base64url')}`;
}

async function connect(name: string) {
    const ws = new WebSocket(`ws://localhost:${server.port}/taikoplus/ws`);
    const inbox: any[] = [];
    const waiters: Array<() => void> = [];
    ws.onmessage = (event) => {
        inbox.push(JSON.parse(String(event.data)));
        waiters.splice(0).forEach((wake) => wake());
    };
    await new Promise((resolve) => (ws.onopen = resolve));
    const next = async (type: string): Promise<any> => {
        for (;;) {
            const index = inbox.findIndex((m) => m.type === type);
            if (index >= 0) return inbox.splice(index, 1)[0];
            await new Promise<void>((resolve) => waiters.push(resolve));
        }
    };
    const send = (m: object) => ws.send(JSON.stringify(m));
    send({ type: 'hello', ticket: ticket(name), name });
    await next('welcome');
    return { ws, send, next };
}

test('tickets', () => {
    expect(verifyTicket(secret, ticket('cab1'))).toBe('cab1');
    expect(verifyTicket(secret, ticket('cab1', 1))).toBeNull();
    expect(verifyTicket('other', ticket('cab1'))).toBeNull();
    expect(verifyTicket(secret, 'garbage')).toBeNull();
});

test('two players reach the same start deadline and exchange hits', async () => {
    const a = await connect('A');
    const b = await connect('B');

    a.send({ type: 'create' });
    const { code } = await a.next('room');
    b.send({ type: 'join', code });
    expect((await b.next('room')).members).toHaveLength(2);

    a.send({ type: 'select', music_id: 'mikugv', chart: 'c'.repeat(64), audio: 'a'.repeat(64) });
    a.send({ type: 'ready', course: 3 });
    b.send({ type: 'ready', course: 2 });
    const [launchA, launchB] = await Promise.all([a.next('launch'), b.next('launch')]);
    expect(launchA.key).toBe(launchB.key);

    a.send({ type: 'loaded' });
    b.send({ type: 'loaded' });
    const [startA, startB] = await Promise.all([a.next('start'), b.next('start')]);
    expect(startA.deadline).toBe(startB.deadline);

    a.send({ type: 'hit', hits: [[1, 1234, 2]] });
    expect((await b.next('hit')).hits).toEqual([[1, 1234, 2]]);

    b.send({ type: 'sync', song: 1500.5, server: 123 });
    expect(await a.next('sync')).toMatchObject({ song: 1500.5, server: 123 });

    b.ws.close();
    expect((await a.next('peer_left')).id).toBeDefined();
    a.ws.close();
});

test('unauthenticated clients are closed', async () => {
    const ws = new WebSocket(`ws://localhost:${server.port}/taikoplus/ws`);
    await new Promise((resolve) => (ws.onopen = resolve));
    const closed = new Promise<number>((resolve) => (ws.onclose = (event) => resolve(event.code)));
    ws.send(JSON.stringify({ type: 'hello', ticket: 'nope' }));
    expect(await closed).toBe(4001);
});
