<?php

namespace App\Services;

class CabinetPairingCodeGenerator
{
    public function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
