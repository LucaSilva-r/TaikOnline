<?php

namespace App\Enums;

enum PlayReportReason: string
{
    /** Macro, autoplay, a modified client. */
    case Cheating = 'cheating';

    /** An offensive name or profile. */
    case Offensive = 'offensive';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cheating => 'Cheating',
            self::Offensive => 'Offensive name or profile',
            self::Other => 'Other',
        };
    }
}
