/**
 * The smallest Pusher-protocol client that does the job (Reverb): one private channel, signed through
 * Laravel's /broadcasting/auth with the session, reconnecting after a drop. Returns a function closing it.
 */
export function listenPrivate(
    url: string,
    channel: string,
    onEvent: (event: string, data: Record<string, unknown>) => void,
): () => void {
    let socket: WebSocket | undefined;
    let retry: ReturnType<typeof setTimeout> | undefined;
    let closed = false;

    const send = (event: string, data: object) => socket?.send(JSON.stringify({ event, data }));
    const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

    function connect(): void {
        socket = new WebSocket(`${url}?protocol=7&client=js&version=8.4.0`);
        socket.onmessage = async (message) => {
            const { event, data } = JSON.parse(message.data);
            const payload = typeof data === 'string' ? JSON.parse(data || '{}') : (data ?? {});
            if (event === 'pusher:connection_established') {
                const response = await fetch('/broadcasting/auth', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                    body: JSON.stringify({ socket_id: payload.socket_id, channel_name: channel }),
                });
                if (response.ok) {
                    send('pusher:subscribe', { channel, auth: (await response.json()).auth });
                }
            } else if (event === 'pusher:ping') {
                send('pusher:pong', {});
            } else if (!event.startsWith('pusher')) {
                onEvent(event, payload);
            }
        };
        socket.onclose = () => {
            if (!closed) {
                retry = setTimeout(connect, 5000);
            }
        };
    }

    connect();

    return () => {
        closed = true;
        clearTimeout(retry);
        socket?.close();
    };
}
