<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class CabinetPairingService
{
    public const CODE_LIFETIME_SECONDS = 30;

    public const SESSION_LIFETIME_SECONDS = 10;

    private const MAX_ALLOCATION_ATTEMPTS = 20;

    public function __construct(private CabinetPairingCodeGenerator $codeGenerator) {}

    /**
     * @return array{
     *     status: string,
     *     session: string|null,
     *     code: string|null,
     *     expires_in: int|null,
     *     command_id: string|null,
     *     access_code: string|null
     * }
     */
    public function poll(
        string $cabinetId,
        string $state,
        bool $accepting,
        ?string $sessionToken,
        ?string $ackCommandId,
    ): array {
        if (! $accepting || ! in_array($state, ['attract', 'shop'], true)) {
            $this->close($sessionToken);

            return $this->result('closed');
        }

        if ($sessionToken === null) {
            return $this->createSession($cabinetId, $state);
        }

        $result = Cache::lock($this->sessionLockKey($sessionToken), 2)->get(function () use (
            $sessionToken,
            $cabinetId,
            $state,
            $ackCommandId,
        ): array {
            $session = Cache::get($this->sessionKey($sessionToken));

            if (! is_array($session) || ($session['cabinet_id'] ?? null) !== $cabinetId) {
                return $this->createSession($cabinetId, $state);
            }

            $command = is_array($session['command'] ?? null) ? $session['command'] : null;
            if ($command !== null && $ackCommandId !== null && hash_equals($command['id'], $ackCommandId)) {
                $session['command'] = null;
                $session['completed'] = true;
                $command = null;
            }

            $session['state'] = $state;

            if ($command !== null) {
                Cache::put($this->sessionKey($sessionToken), $session, self::SESSION_LIFETIME_SECONDS);

                return $this->result(
                    status: 'claimed',
                    sessionToken: $sessionToken,
                    commandId: $command['id'],
                    accessCode: $command['access_code'],
                );
            }

            if (($session['completed'] ?? false) === true) {
                Cache::put($this->sessionKey($sessionToken), $session, self::SESSION_LIFETIME_SECONDS);

                return $this->result('complete', $sessionToken);
            }

            $now = now()->timestamp;
            $expiresAt = (int) ($session['code_expires_at'] ?? 0);
            if ($expiresAt <= $now) {
                $this->forgetCode($session['code'] ?? null);
                [$session['code'], $session['code_expires_at']] = $this->reserveCode($sessionToken);
                $expiresAt = $session['code_expires_at'];
            }

            Cache::put($this->sessionKey($sessionToken), $session, self::SESSION_LIFETIME_SECONDS);

            return $this->result(
                status: 'active',
                sessionToken: $sessionToken,
                code: $session['code'],
                expiresIn: max(1, $expiresAt - $now),
            );
        });

        return is_array($result) ? $result : $this->result('busy', $sessionToken);
    }

    public function claim(string $code, string $accessCode): bool
    {
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1 || preg_match('/\A[0-9]{20}\z/', $accessCode) !== 1) {
            return false;
        }

        $claimed = Cache::lock($this->codeLockKey($code), 2)->get(function () use ($code, $accessCode): bool {
            $sessionToken = Cache::get($this->codeKey($code));
            if (! is_string($sessionToken)) {
                return false;
            }

            $result = Cache::lock($this->sessionLockKey($sessionToken), 2)->get(function () use (
                $sessionToken,
                $code,
                $accessCode,
            ): bool {
                $session = Cache::get($this->sessionKey($sessionToken));
                if (! is_array($session)
                    || ($session['code'] ?? null) !== $code
                    || ($session['completed'] ?? false) === true
                    || is_array($session['command'] ?? null)
                    || (int) ($session['code_expires_at'] ?? 0) <= now()->timestamp) {
                    return false;
                }

                $session['command'] = [
                    'id' => Str::uuid()->toString(),
                    'access_code' => $accessCode,
                ];
                $session['code'] = null;
                $session['code_expires_at'] = null;

                Cache::forget($this->codeKey($code));
                Cache::put($this->sessionKey($sessionToken), $session, self::SESSION_LIFETIME_SECONDS);

                return true;
            });

            return $result === true;
        });

        return $claimed === true;
    }

    /**
     * @return array{
     *     status: string,
     *     session: string|null,
     *     code: string|null,
     *     expires_in: int|null,
     *     command_id: string|null,
     *     access_code: string|null
     * }
     */
    private function createSession(string $cabinetId, string $state): array
    {
        for ($attempt = 0; $attempt < self::MAX_ALLOCATION_ATTEMPTS; $attempt++) {
            $sessionToken = Str::random(64);
            [$code, $expiresAt] = $this->reserveCode($sessionToken);
            $session = [
                'cabinet_id' => $cabinetId,
                'state' => $state,
                'code' => $code,
                'code_expires_at' => $expiresAt,
                'command' => null,
                'completed' => false,
            ];

            if (Cache::add($this->sessionKey($sessionToken), $session, self::SESSION_LIFETIME_SECONDS)) {
                return $this->result(
                    status: 'active',
                    sessionToken: $sessionToken,
                    code: $code,
                    expiresIn: self::CODE_LIFETIME_SECONDS,
                );
            }

            Cache::forget($this->codeKey($code));
        }

        throw new RuntimeException('Unable to allocate a cabinet pairing session.');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function reserveCode(string $sessionToken): array
    {
        for ($attempt = 0; $attempt < self::MAX_ALLOCATION_ATTEMPTS; $attempt++) {
            $code = $this->codeGenerator->generate();
            if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
                continue;
            }

            if (Cache::add($this->codeKey($code), $sessionToken, self::CODE_LIFETIME_SECONDS)) {
                return [$code, now()->addSeconds(self::CODE_LIFETIME_SECONDS)->timestamp];
            }
        }

        throw new RuntimeException('Unable to allocate a cabinet pairing code.');
    }

    private function close(?string $sessionToken): void
    {
        if ($sessionToken === null) {
            return;
        }

        Cache::lock($this->sessionLockKey($sessionToken), 2)->get(function () use ($sessionToken): void {
            $session = Cache::get($this->sessionKey($sessionToken));
            if (is_array($session)) {
                $this->forgetCode($session['code'] ?? null);
            }

            Cache::forget($this->sessionKey($sessionToken));
        });
    }

    private function forgetCode(mixed $code): void
    {
        if (is_string($code) && preg_match('/\A[0-9]{6}\z/', $code) === 1) {
            Cache::forget($this->codeKey($code));
        }
    }

    /**
     * @return array{
     *     status: string,
     *     session: string|null,
     *     code: string|null,
     *     expires_in: int|null,
     *     command_id: string|null,
     *     access_code: string|null
     * }
     */
    private function result(
        string $status,
        ?string $sessionToken = null,
        ?string $code = null,
        ?int $expiresIn = null,
        ?string $commandId = null,
        ?string $accessCode = null,
    ): array {
        return [
            'status' => $status,
            'session' => $sessionToken,
            'code' => $code,
            'expires_in' => $expiresIn,
            'command_id' => $commandId,
            'access_code' => $accessCode,
        ];
    }

    private function sessionKey(string $sessionToken): string
    {
        return 'cabinet-pairing:session:'.$sessionToken;
    }

    private function sessionLockKey(string $sessionToken): string
    {
        return 'cabinet-pairing:session-lock:'.$sessionToken;
    }

    private function codeKey(string $code): string
    {
        return 'cabinet-pairing:code:'.$code;
    }

    private function codeLockKey(string $code): string
    {
        return 'cabinet-pairing:code-lock:'.$code;
    }
}
