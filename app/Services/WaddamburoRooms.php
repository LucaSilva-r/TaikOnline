<?php

namespace App\Services;

use App\Enums\SanctionType;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Two-player online rooms. They only introduce the players: a room is a code, a name, its host and guest, and maybe a
 * password, in the status cache store for as long as its players' games keep renewing it, so the list never shows a game
 * that crashed. Its presence channel carries the connection setup between them. The game itself then runs peer to peer,
 * and nothing about it reaches the server. Public rooms are listed; the others are joined by code.
 */
class WaddamburoRooms
{
    public const JOINED = 'joined';

    public const NO_ROOM = 'no_room';

    public const WRONG_PASSWORD = 'wrong_password';

    /** Games renew every 15 s, so this outlasts two lost renewals. */
    private const TTL_SECONDS = 45;

    private const CREDENTIAL_TTL_SECONDS = 6 * 3600;

    /** No 0/O or 1/I: codes get read out over voice chat. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 6;

    private const QUEUE_KEY = 'wdb-room-queue';

    private const QUEUE_TTL_SECONDS = 30;

    private const INDEX_KEY = 'wdb-room-index';

    private const LIST_LIMIT = 100;

    /** Whether the player may be in a room at all: a home player with a player, and not banned. */
    public function mayPlay(User $user): bool
    {
        $baid = $user->player?->baid;

        return $baid !== null && Sanction::activeFor((int) $baid, SanctionType::Ban) === null;
    }

    /** Opens a room hosted by the player, named after them unless they say otherwise, and returns its code. */
    public function create(User $host, ?string $name = null, bool $isPublic = true, ?string $password = null): string
    {
        $room = [
            'host' => $host->id,
            'guest' => null,
            'host_name' => $host->name,
            'name' => $this->roomName($host, $name),
            'is_public' => $isPublic,
            'password' => $password === null || $password === '' ? null : Hash::make($password),
        ];
        do {
            $code = '';
            for ($index = 0; $index < self::CODE_LENGTH; $index++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (! Cache::store('status')->add($this->key($code), $room, self::TTL_SECONDS));
        $this->addToIndex($code);

        return $code;
    }

    /** Takes the guest's seat: JOINED, NO_ROOM (none, or someone else has it) or WRONG_PASSWORD. A member joining again is fine. */
    public function join(string $code, User $user, ?string $password = null): string
    {
        return Cache::store('status')->lock($this->key($code).':lock', 5)->block(3, function () use ($code, $user, $password): string {
            $room = $this->room($code);
            if ($room === null || ($room['guest'] !== null && $room['guest'] !== $user->id && $room['host'] !== $user->id)) {
                return self::NO_ROOM;
            }
            if ($room['host'] === $user->id || $room['guest'] === $user->id) {
                return self::JOINED;
            }
            if ($room['password'] !== null && ! Hash::check((string) $password, $room['password'])) {
                return self::WRONG_PASSWORD;
            }
            $this->store($code, [...$room, 'guest' => $user->id]);

            return self::JOINED;
        });
    }

    /**
     * The host changes their room: any of name, is_public, password ('' or null removes it). False for anyone else.
     *
     * @param  array{name?: string|null, is_public?: bool, password?: string|null}  $changes
     */
    public function update(string $code, User $user, array $changes): bool
    {
        return (bool) Cache::store('status')->lock($this->key($code).':lock', 5)->block(3, function () use ($code, $user, $changes): bool {
            $room = $this->room($code);
            if ($room === null || $room['host'] !== $user->id) {
                return false;
            }
            if (array_key_exists('name', $changes)) {
                $room['name'] = $this->roomName($user, $changes['name']);
            }
            if (array_key_exists('is_public', $changes)) {
                $room['is_public'] = (bool) $changes['is_public'];
            }
            if (array_key_exists('password', $changes)) {
                $room['password'] = in_array($changes['password'], [null, ''], true) ? null : Hash::make($changes['password']);
            }
            $this->store($code, $room);

            return true;
        });
    }

    /**
     * The rooms anyone can see and join: public, with a free seat, newest first.
     *
     * @return list<array{code: string, name: string, host: string, has_password: bool}>
     */
    public function listed(): array
    {
        $listed = [];
        foreach (array_reverse(Cache::store('status')->get(self::INDEX_KEY, [])) as $code) {
            $room = $this->room($code);
            if ($room !== null && $room['is_public'] && $room['guest'] === null) {
                $listed[] = ['code' => $code, 'name' => $room['name'], 'host' => $room['host_name'], 'has_password' => $room['password'] !== null];
            }
            if (count($listed) === self::LIST_LIMIT) {
                break;
            }
        }

        return $listed;
    }

    /** What the room's own players see of it. @return array{code: string, name: string, is_public: bool, has_password: bool}|null */
    public function describe(string $code): ?array
    {
        $room = $this->room($code);

        return $room === null ? null : ['code' => $code, 'name' => $room['name'], 'is_public' => $room['is_public'], 'has_password' => $room['password'] !== null];
    }

    /**
     * Quick play: takes the waiting player's room, or becomes the waiting player with a new, unlisted one. Whoever waits
     * renews their place (keepAlive), so a game that vanishes stops being matched within QUEUE_TTL_SECONDS.
     *
     * @return string the room's code
     */
    public function quickPlay(User $user): string
    {
        return Cache::store('status')->lock(self::QUEUE_KEY.':lock', 5)->block(3, function () use ($user): string {
            $waiting = Cache::store('status')->get(self::QUEUE_KEY);
            if ($waiting !== null && $waiting['host'] === $user->id) {
                Cache::store('status')->put(self::QUEUE_KEY, $waiting, self::QUEUE_TTL_SECONDS);

                return $waiting['code'];
            }
            if ($waiting !== null && $this->join($waiting['code'], $user) === self::JOINED) {
                Cache::store('status')->forget(self::QUEUE_KEY);

                return $waiting['code'];
            }
            $code = $this->create($user, isPublic: false);
            Cache::store('status')->put(self::QUEUE_KEY, ['code' => $code, 'host' => $user->id], self::QUEUE_TTL_SECONDS);

            return $code;
        });
    }

    /** A member's game says it's still here: the room lasts, and so does their place in the quick play queue. False when the room is gone. */
    public function keepAlive(string $code, User $user): bool
    {
        $room = $this->room($code);
        if ($room === null || ! in_array($user->id, [$room['host'], $room['guest']], true)) {
            return false;
        }
        $this->store($code, $room);
        $waiting = Cache::store('status')->get(self::QUEUE_KEY);
        if ($waiting !== null && $waiting['code'] === $code && $waiting['host'] === $user->id) {
            Cache::store('status')->put(self::QUEUE_KEY, $waiting, self::QUEUE_TTL_SECONDS);
        }

        return true;
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
                if ((Cache::store('status')->get(self::QUEUE_KEY)['code'] ?? null) === $code) {
                    Cache::store('status')->forget(self::QUEUE_KEY);
                }
            } elseif ($room['guest'] === $user->id) {
                $this->store($code, [...$room, 'guest' => null]);
            }
        });
    }

    public function isMember(string $code, User $user): bool
    {
        $room = $this->room($code);

        return $room !== null && in_array($user->id, [$room['host'], $room['guest']], true);
    }

    /**
     * Where the players find each other: this instance's coturn when it has one, with TURN credentials good for a long
     * sitting (coturn's use-auth-secret: it checks the signature with the same secret, and needs no call from here).
     * Without one, a public STUN server: most players still connect, just with no relay to fall back on.
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
        $username = (now()->getTimestamp() + self::CREDENTIAL_TTL_SECONDS).":{$user->id}";

        return [
            ['urls' => ["stun:{$host}:{$port}"]],
            [
                'urls' => ["turn:{$host}:{$port}?transport=udp"],
                'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            ],
        ];
    }

    /** A room's name is shown to everyone browsing: one line, short, and a muted player's is just their own name. */
    private function roomName(User $user, ?string $name): string
    {
        $name = trim((string) preg_replace('/\p{C}+/u', '', (string) $name));
        $muted = Sanction::activeFor((int) $user->player?->baid, SanctionType::Mute) !== null;

        return $name === '' || $muted ? $user->name : mb_substr($name, 0, 32);
    }

    private function addToIndex(string $code): void
    {
        Cache::store('status')->lock(self::INDEX_KEY.':lock', 5)->block(3, function () use ($code): void {
            $live = array_filter(Cache::store('status')->get(self::INDEX_KEY, []), fn (string $known): bool => $this->room($known) !== null);
            Cache::store('status')->forever(self::INDEX_KEY, [...array_values($live), $code]);
        });
    }

    /** @param  array<string, mixed>  $room */
    private function store(string $code, array $room): void
    {
        Cache::store('status')->put($this->key($code), $room, self::TTL_SECONDS);
    }

    /** @return array{host: int, guest: int|null, host_name: string, name: string, is_public: bool, password: string|null}|null */
    private function room(string $code): ?array
    {
        return Cache::store('status')->get($this->key($code));
    }

    private function key(string $code): string
    {
        return "wdb-room:{$code}";
    }
}
