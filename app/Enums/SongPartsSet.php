<?php

namespace App\Enums;

enum SongPartsSet: string
{
    case A3 = 'A3';
    case GMT = 'GMT';
    case Animal = 'animal';
    case Butto = 'butto';
    case Dojo = 'dojo';
    case Gumi = 'gumi';
    case I7Id7 = 'i7id7';
    case I7Natsu = 'i7natsu';
    case I7Rev = 'i7rev';
    case I7Tri = 'i7tri';
    case Ia = 'ia';
    case Imas = 'imas';
    case ImasCg = 'imasCG';
    case ImasMl = 'imasML';
    case ImasSideM = 'imasSideM';
    case Kinbaku = 'kinbaku';
    case Kobayashi = 'kobayashi';
    case Kumamon = 'kumamon';
    case Lovelive = 'lovelive';
    case Mario = 'mario';
    case Miku = 'miku';
    case Mikugumi = 'mikugumi';
    case Pzd = 'pzd';
    case Taiko = 'taiko';
    case Toho = 'toho';
    case Tt = 'tt';
    case Ymck = 'ymck';
    case Yokai = 'yokai';
    case YokaiHatsukoi = 'yokai_hatsukoi';
    case YokaiMatsuri = 'yokai_matsuri';
    case YokaiTokoroten = 'yokai_tokoroten';
    case YokaiYougota = 'yokai_yougota';

    public function label(): string
    {
        return match ($this) {
            self::A3 => 'A3',
            self::GMT => 'GMT',
            self::Animal => 'Animal',
            self::Butto => 'Butto',
            self::Dojo => 'Dojo',
            self::Gumi => 'Gumi',
            self::I7Id7 => 'i7id7 (Idolmaster 7th)',
            self::I7Natsu => 'i7natsu (Idolmaster Summer)',
            self::I7Rev => 'i7rev (Idolmaster Rev)',
            self::I7Tri => 'i7tri (Idolmaster Tri)',
            self::Ia => 'Ia (Infinite Axess)',
            self::Imas => 'The Idolmaster',
            self::ImasCg => 'Idolmaster Crystal Live',
            self::ImasMl => 'Idolmaster Million Live!',
            self::ImasSideM => 'Idolmaster SideM',
            self::Kinbaku => 'Kinbaku (Bondage)',
            self::Kobayashi => 'Kobayashi',
            self::Kumamon => 'Kumamon',
            self::Lovelive => 'Love Live!',
            self::Mario => 'Mario',
            self::Miku => 'Hatsune Miku (Vocaloid)',
            self::Mikugumi => 'Miku x Gumi (Vocaloid Collaboration)',
            self::Pzd => 'Puzzle & Dragons',
            self::Taiko => 'Taiko Original',
            self::Toho => 'Toho',
            self::Tt => 'TT',
            self::Ymck => 'YMCK',
            self::Yokai => 'Yo-kai Watch',
            self::YokaiHatsukoi => 'Yo-kai Watch (Hatsukoi)',
            self::YokaiMatsuri => 'Yo-kai Watch (Matsuri)',
            self::YokaiTokoroten => 'Yo-kai Watch (Tokoroten)',
            self::YokaiYougota => 'Yo-kai Watch (Yougota)',
        };
    }
}
