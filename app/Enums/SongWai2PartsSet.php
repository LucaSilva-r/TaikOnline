<?php

namespace App\Enums;

enum SongWai2PartsSet: string
{
    case None = '';
    case A3 = 'A3';
    case A302 = 'A3_02';
    case I7Id7 = 'i7id7';
    case I7Natsu = 'i7natsu';
    case I7Rev = 'i7rev';
    case I7Tri = 'i7tri';
    case Poptep = 'poptep';
    case Taiko = 'taiko';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::A3 => 'A3',
            self::A302 => 'A3 Encore',
            self::I7Id7 => 'i7id7 (Idolmaster 7th)',
            self::I7Natsu => 'i7natsu (Idolmaster Summer)',
            self::I7Rev => 'i7rev (Idolmaster Rev)',
            self::I7Tri => 'i7tri (Idolmaster Tri)',
            self::Poptep => 'Poptep',
            self::Taiko => 'Taiko Original',
        };
    }
}
