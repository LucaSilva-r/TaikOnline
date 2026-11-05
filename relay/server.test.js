import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { once } from 'node:events';
import { test } from 'node:test';
import WebSocket from 'ws';
import { startRelay, verifyTicket } from './server.js';

const secret = 'test-secret';

function ticket(room, member, expires = Math.floor(Date.now() / 1000) + 60, key = secret) {
    const payload = Buffer.from(`${room}:${member}:${expires}`).toString('base64url');
    return `${payload}.${createHmac('sha256', key).update(payload).digest('base64url')}`;
}

async function connect(port, value) {
    const socket = new WebSocket(`ws://127.0.0.1:${port}/?ticket=${encodeURIComponent(value)}`);
    socket.received = [];
    socket.on('message', (data) => socket.received.push(Buffer.from(data)));
    await once(socket, 'open');
    return socket;
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 100));

test('a ticket is the room and member it was signed for, until it expires', () => {
    assert.deepEqual(verifyTicket(ticket('ABC234', '7'), secret), { room: 'ABC234', member: '7' });
    assert.equal(verifyTicket(ticket('ABC234', '7', 100), secret), null);
    assert.equal(verifyTicket(ticket('ABC234', '7', undefined, 'other-secret'), secret), null);
    assert.equal(verifyTicket('garbage', secret), null);
    assert.equal(verifyTicket(null, secret), null);
});

test('pairs two members of a room and passes binary frames between them only', async () => {
    const server = startRelay({ port: 0, secret });
    await once(server, 'listening');
    const port = server.address().port;
    try {
        const first = await connect(port, ticket('ROOM22', '1'));
        const second = await connect(port, ticket('ROOM22', '2'));
        const elsewhere = await connect(port, ticket('OTHER2', '3'));

        first.send(Buffer.from([0xd3, 1, 2, 3]));
        second.send(Buffer.from([0xd1, 9]));
        first.send('text is ignored');
        await settle();

        assert.deepEqual(second.received, [Buffer.from([0xd3, 1, 2, 3])]);
        assert.deepEqual(first.received, [Buffer.from([0xd1, 9])]);
        assert.deepEqual(elsewhere.received, []);

        // A third member of a full room, and a forged ticket, are turned away.
        const third = new WebSocket(`ws://127.0.0.1:${port}/?ticket=${ticket('ROOM22', '4')}`);
        const [fullCode] = await once(third, 'close');
        assert.equal(fullCode, 4003);
        const forged = new WebSocket(`ws://127.0.0.1:${port}/?ticket=${ticket('ROOM22', '5', undefined, 'wrong')}`);
        const [forgedCode] = await once(forged, 'close');
        assert.equal(forgedCode, 4001);

        // Too big for a peer-link packet: the sender is cut off.
        first.send(Buffer.alloc(4096));
        const [bigCode] = await once(first, 'close');
        assert.equal(bigCode, 1009);

        for (const socket of [second, elsewhere]) {
            socket.close();
        }
    } finally {
        server.close();
        server.closeAllConnections?.();
    }
});

test('logs who connected and how each connection ended, without addresses', async () => {
    const lines = [];
    const server = startRelay({ port: 0, secret, log: (line) => lines.push(line) });
    await once(server, 'listening');
    const port = server.address().port;
    try {
        const first = await connect(port, ticket('LOGS22', '1'));
        const second = await connect(port, ticket('LOGS22', '2'));
        first.send(Buffer.from([0xd3, 1]));
        await settle();
        first.close();
        await settle();
        const forged = new WebSocket(`ws://127.0.0.1:${port}/?ticket=nope`);
        await once(forged, 'close');
        second.close();
        await settle();

        assert.ok(lines.includes('room LOGS22 member 1 connected, 1 of 2 in the room'));
        assert.ok(lines.includes('room LOGS22 member 2 connected, 2 of 2 in the room'));
        assert.ok(lines.some((line) => /^room LOGS22 member 1 closed 1005 after [\d.]+ s, 1 frames in, 1 passed on$/.test(line)));
        assert.ok(lines.includes('rejected: bad or expired ticket'));
        assert.ok(lines.every((line) => !line.includes('127.0.0.1')));
    } finally {
        server.close();
        server.closeAllConnections?.();
    }
});
