<?php

namespace App\Enums;

enum SanctionType: string
{
    /** Uploads refused, web and cabinet logins blocked. */
    case Ban = 'ban';

    /** Uploads accepted and shown to the player only; everyone else sees nothing. */
    case Shadowban = 'shadowban';

    /** Stored now, enforced once forum and chat exist. */
    case Mute = 'mute';

    public function label(): string
    {
        return match ($this) {
            self::Ban => 'Ban',
            self::Shadowban => 'Shadowban',
            self::Mute => 'Mute',
        };
    }
}
