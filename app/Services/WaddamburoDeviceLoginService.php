<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Waddamburo's in-game login without a password (a device-code flow): the game asks for a code,
 * shows it, and polls; the player enters the code on the website while logged in and approves the
 * device, and the game's next poll receives a 'wdb' token for that player.
 */
class WaddamburoDeviceLoginService
{
    public const LIFETIME_SECONDS = 300;

    public const POLL_INTERVAL_SECONDS = 3;

    private const MAX_ALLOCATION_ATTEMPTS = 20;

    public function __construct(private CabinetPairingCodeGenerator $codeGenerator) {}

    /**
     * @return array{device_code: string, user_code: string, expires_in: int, interval: int}
     */
    public function start(string $device): array
    {
        $deviceCode = Str::random(64);
        for ($attempt = 0; $attempt < self::MAX_ALLOCATION_ATTEMPTS; $attempt++) {
            $userCode = $this->codeGenerator->generate();
            if (Cache::add($this->codeKey($userCode), $deviceCode, self::LIFETIME_SECONDS)) {
                Cache::put($this->deviceKey($deviceCode), [
                    'device' => $device,
                    'user_code' => $userCode,
                    'user_id' => null,
                    'denied' => false,
                ], self::LIFETIME_SECONDS);

                return [
                    'device_code' => $deviceCode,
                    'user_code' => $userCode,
                    'expires_in' => self::LIFETIME_SECONDS,
                    'interval' => self::POLL_INTERVAL_SECONDS,
                ];
            }
        }

        throw new RuntimeException('Unable to allocate a device login code.');
    }

    /** The device name waiting behind a code the player typed, or null when there is none. */
    public function pendingDevice(string $userCode): ?string
    {
        $entry = $this->entryForUserCode($userCode);

        return $entry !== null && $entry['user_id'] === null && ! $entry['denied'] ? $entry['device'] : null;
    }

    /** Approves (or denies) the device behind a code; false when the code is unknown or already used. */
    public function decide(string $userCode, User $user, bool $approve): bool
    {
        $deviceCode = Cache::get($this->codeKey($userCode));
        if (! is_string($deviceCode)) {
            return false;
        }

        $entry = Cache::get($this->deviceKey($deviceCode));
        if (! is_array($entry) || $entry['user_id'] !== null || $entry['denied']) {
            return false;
        }

        $entry[$approve ? 'user_id' : 'denied'] = $approve ? $user->id : true;
        Cache::put($this->deviceKey($deviceCode), $entry, self::LIFETIME_SECONDS);
        Cache::forget($this->codeKey($userCode));

        return true;
    }

    /**
     * The game's poll: 'pending', 'denied', 'expired', or 'approved' with the user and device name
     * (the entry is consumed then, so a token is issued once).
     *
     * @return array{status: string, user?: User, device?: string}
     */
    public function poll(string $deviceCode): array
    {
        $entry = Cache::get($this->deviceKey($deviceCode));
        if (! is_array($entry)) {
            return ['status' => 'expired'];
        }
        if ($entry['denied']) {
            Cache::forget($this->deviceKey($deviceCode));

            return ['status' => 'denied'];
        }
        if ($entry['user_id'] === null) {
            return ['status' => 'pending'];
        }

        Cache::forget($this->deviceKey($deviceCode));
        $user = User::query()->find($entry['user_id']);

        return $user instanceof User
            ? ['status' => 'approved', 'user' => $user, 'device' => $entry['device']]
            : ['status' => 'expired'];
    }

    /**
     * @return array{device: string, user_code: string, user_id: int|null, denied: bool}|null
     */
    private function entryForUserCode(string $userCode): ?array
    {
        $deviceCode = Cache::get($this->codeKey($userCode));
        $entry = is_string($deviceCode) ? Cache::get($this->deviceKey($deviceCode)) : null;

        return is_array($entry) ? $entry : null;
    }

    private function codeKey(string $userCode): string
    {
        return 'wdb-device:code:'.$userCode;
    }

    private function deviceKey(string $deviceCode): string
    {
        return 'wdb-device:device:'.hash('sha256', $deviceCode);
    }
}
