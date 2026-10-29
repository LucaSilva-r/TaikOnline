<?php

namespace App\Enums;

enum SanctionType: string
{
    /**
     * A restriction (osu!'s word): the player still logs in and plays, and is told. Their plays are hidden
     * from everyone else, those made while banned never count, and they cannot report.
     */
    case Ban = 'ban';

    /** Uploads accepted and shown to the player only; everyone else sees nothing. */
    case Shadowban = 'shadowban';

    /** Stored now, enforced once forum and chat exist. */
    case Mute = 'mute';

    /** Their reports of plays are refused (a player who reports in bad faith). */
    case ReportBlock = 'report_block';

    /** @return list<string> the sanctions whose players' plays only they see */
    public static function hidingPlays(): array
    {
        return [self::Ban->value, self::Shadowban->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Ban => 'Ban',
            self::Shadowban => 'Shadowban',
            self::Mute => 'Mute',
            self::ReportBlock => 'Report block',
        };
    }
}
