<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CabinetService
{
    private const ALLOCATION_ATTEMPTS = 16;

    public function allocate(User $user, ?string $nickname = null): Cabinet
    {
        for ($i = 0; $i < self::ALLOCATION_ATTEMPTS; $i++) {
            $serial = $this->generateSerial();

            try {
                return DB::transaction(function () use ($user, $serial, $nickname) {
                    $cabinet = Cabinet::query()->create([
                        'serial' => $serial,
                        'user_id' => $user->id,
                        'nickname' => $nickname,
                        'registered_at' => now(),
                    ]);

                    return $cabinet;
                });
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    continue;
                }
                throw $e;
            }
        }

        throw new RuntimeException('Failed to allocate unique cabinet serial.');
    }

    public function revoke(Cabinet $cabinet): void
    {
        if ($cabinet->isDefault()) {
            throw new RuntimeException('Default cabinet cannot be revoked.');
        }

        $cabinet->delete();
    }

    public function recordHeartbeat(string $serial, ?string $ip): void
    {
        Cabinet::query()
            ->whereKey($serial)
            ->update([
                'last_heartbeat_at' => now(),
                'last_ip' => $ip,
            ]);
    }

    private function generateSerial(): string
    {
        $suffix = str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);

        return Cabinet::SERIAL_PREFIX.$suffix;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
