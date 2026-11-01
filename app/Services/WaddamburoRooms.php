<?php

namespace App\Services;

use App\Enums\SanctionType;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Two-player online rooms. They only introduce the players: a room is a code, its host and its guest, in the
 * status cache store for a few hours, and its presence channel carries the connection setup between them.
 * The game itself then runs peer to peer, and nothing about it reaches the server.
 */
class WaddamburoRooms
{
    private const TTL_SECONDS = 6 * 3600;

    /** No 0/O or 1/I: codes get read out over voice chat. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 6;

    /** Whether the player may be in a room at all: a home player with a player, and not banned. */
    public function mayPlay(User $user): bool
    {
        $baid = $user->player?->baid;

        return $baid !== null && Sanction::activeFor((int) $baid, SanctionType::Ban) === null;
    }

    /** Opens a room hosted by the player, and returns its code. */
    public function create(User $host): string
    {
        do {
            $code = '';
            for ($index = 0; $index < self::CODE_LENGTH; $index++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (! Cache::store('status')->add($this->key($code), ['host' => $host->id, 'guest' => null], self::TTL_SECONDS));

        return $code;
    }

    /** Takes the guest's seat; false when there's no such room or someone else has it. A member joining again is fine. */
    public function join(string $code, User $user): bool
    {
        return (bool) Cache::store('status')->lock($this->key($code).':lock', 5)->block(3, function () use ($code, $user): bool {
            $room = $this->room($code);
            if ($room === null || ($room['guest'] !== null && $room['guest'] !== $user->id && $room['host'] !== $user->id)) {
                return false;
            }
            if ($room['host'] !== $user->id) {
                Cache::store('status')->put($this->key($code), [...$room, 'guest' => $user->id], self::TTL_SECONDS);
            }

            return true;
        });
    }

    /** The host leaving closes the room; the guest leaving frees their seat. */
    public function leave(string $code, User $user): void
    {
        Cache::store('status')->lock($this->key($code).':lock', 5)->block(3, function () use ($code, $user): void {
            $room = $this->room($code);
            if ($room === null) {
                return;
            }
            if ($room['host'] === $user->id) {
                Cache::store('status')->forget($this->key($code));
            } elseif ($room['guest'] === $user->id) {
                Cache::store('status')->put($this->key($code), [...$room, 'guest' => null], self::TTL_SECONDS);
            }
        });
    }

    public function isMember(string $code, User $user): bool
    {
        $room = $this->room($code);

        return $room !== null && in_array($user->id, [$room['host'], $room['guest']], true);
    }

    /**
     * Where the players find each other: this instance's coturn when it has one, with TURN credentials good for as
     * long as a room lasts (coturn's use-auth-secret: it checks the signature with the same secret, and needs no
     * call from here). Without one, a public STUN server: most players still connect, just with no relay to fall back on.
     *
     * @return list<array{urls: list<string>, username?: string, credential?: string}>
     */
    public function iceServers(User $user): array
    {
        $host = config('services.turn.host');
        $secret = config('services.turn.secret');
        if (! is_string($host) || $host === '' || ! is_string($secret) || $secret === '') {
            return [['urls' => ['stun:stun.cloudflare.com:3478']]];
        }
        $port = (int) config('services.turn.port');
        $username = (now()->getTimestamp() + self::TTL_SECONDS).":{$user->id}";

        return [
            ['urls' => ["stun:{$host}:{$port}"]],
            [
                'urls' => ["turn:{$host}:{$port}?transport=udp"],
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            ],
        ];
    }

    /** @return array{host: int, guest: int|null}|null */
    private function room(string $code): ?array
    {
        return Cache::store('status')->get($this->key($code));
    }

    private function key(string $code): string
    {
        return "wdb-room:{$code}";
    }
}
