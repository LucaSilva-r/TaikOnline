<?php

namespace App\Enums;

enum SongGenre: string
{
    case Jpop = 'jpop';
    case Anime = 'anime';
    case Classical = 'classical';
    case GameMusic = 'game_music';
    case NamcoOriginal = 'namco_original';
    case Variety = 'variety';
    case Vocaloid = 'vocaloid';
    case Medley = 'medley';
    case ChildrensSongs = 'childrens_songs';

    public static function fromXml(string $xmlValue): self
    {
        return match ($xmlValue) {
            'J-POP' => self::Jpop,
            'アニメ' => self::Anime,
            'クラシック' => self::Classical,
            'ゲームミュージック' => self::GameMusic,
            'ナムコオリジナル' => self::NamcoOriginal,
            'バラエティ' => self::Variety,
            'ボーカロイド' => self::Vocaloid,
            'メドレー' => self::Medley,
            '童謡' => self::ChildrensSongs,
        };
    }

    public static function tryFromXml(string $xmlValue): ?self
    {
        return match ($xmlValue) {
            'J-POP' => self::Jpop,
            'アニメ' => self::Anime,
            'クラシック' => self::Classical,
            'ゲームミュージック' => self::GameMusic,
            'ナムコオリジナル' => self::NamcoOriginal,
            'バラエティ' => self::Variety,
            'ボーカロイド' => self::Vocaloid,
            'メドレー' => self::Medley,
            '童謡' => self::ChildrensSongs,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Jpop => 'J-POP',
            self::Anime => 'Anime',
            self::Classical => 'Classical',
            self::GameMusic => 'Game Music',
            self::NamcoOriginal => 'Namco Original',
            self::Variety => 'Variety',
            self::Vocaloid => 'Vocaloid',
            self::Medley => 'Medley',
            self::ChildrensSongs => "Children's Songs",
        };
    }

    public function labelJp(): string
    {
        return match ($this) {
            self::Jpop => 'J-POP',
            self::Anime => 'アニメ',
            self::Classical => 'クラシック',
            self::GameMusic => 'ゲームミュージック',
            self::NamcoOriginal => 'ナムコオリジナル',
            self::Variety => 'バラエティ',
            self::Vocaloid => 'ボーカロイド',
            self::Medley => 'メドレー',
            self::ChildrensSongs => '童謡',
        };
    }
}
