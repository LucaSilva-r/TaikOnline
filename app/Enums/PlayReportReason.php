<?php

namespace App\Enums;

enum PlayReportReason: string
{
    /** The chart played by a bot. */
    case Autoplay = 'autoplay';

    /** A macro or turbo button, usually on drumrolls and balloons. */
    case Macro = 'macro';

    /** Any other cheating. */
    case Cheating = 'cheating';

    /** An offensive name or profile. */
    case Offensive = 'offensive';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Autoplay => 'Autoplay',
            self::Macro => 'Roll/balloon macro',
            self::Cheating => 'Other cheating',
            self::Offensive => 'Offensive name or profile',
            self::Other => 'Other',
        };
    }
}
