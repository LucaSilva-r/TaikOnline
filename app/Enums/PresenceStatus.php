<?php

namespace App\Enums;

/** What a player chooses to show others about being online (the status menu). */
enum PresenceStatus: string
{
    case Online = 'online';
    case Invisible = 'invisible';

    /** Whether everyone but the server is kept from seeing the player's Waddamburo status. */
    public function hidesStatus(): bool
    {
        return $this === self::Invisible;
    }
}
