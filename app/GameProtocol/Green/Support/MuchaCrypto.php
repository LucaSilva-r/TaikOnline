<?php

namespace App\GameProtocol\Green\Support;

use InvalidArgumentException;
use phpseclib3\Crypt\Blowfish;

class MuchaCrypto
{
    public function tokenKey(?string $sendDate): string
    {
        $date = $sendDate !== null && preg_match('/^\d{8}$/', $sendDate) === 1
            ? $sendDate
            : now()->format('Ymd');

        return $date[7].substr($date, 0, 7);
    }

    public function encryptToken(string|int $token, string $key): string
    {
        return bin2hex($this->cipher($key)->encrypt((string) $token));
    }

    public function decryptToken(string $encryptedToken, string $key): string
    {
        if (preg_match('/^(?:[0-9a-fA-F]{16})+$/', $encryptedToken) !== 1) {
            throw new InvalidArgumentException('Mucha token ciphertext must be hex encoded 8-byte blocks.');
        }

        return $this->cipher($key)->decrypt(hex2bin($encryptedToken));
    }

    private function cipher(string $key): Blowfish
    {
        if (strlen($key) !== 8) {
            throw new InvalidArgumentException('Mucha token key must be exactly 8 bytes.');
        }

        $cipher = new Blowfish('cbc');
        $cipher->setKey($key);
        $cipher->setIV($key);

        return $cipher;
    }
}
