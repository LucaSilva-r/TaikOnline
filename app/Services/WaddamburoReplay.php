<?php

namespace App\Services;

use App\Models\Player;
use App\Notifications\WaddamburoNotice;

/**
 * Checks an uploaded replay before it is kept, so the scorer only ever reads what an honest client writes.
 * The format is Waddamburo's TaikoReplay: gzipped, a version byte, the input count, then per input a
 * signed 7-bit-encoded tick delta (100 ns) and an action byte.
 */
class WaddamburoReplay
{
    /** Decompressed size limit: about 4x the longest stored replay (64 KB), and it bounds the scorer's memory. */
    public const MAX_BYTES = 256 * 1024;

    /** Change together with the scorer's release, before clients write a new format. */
    private const FORMAT = 1;

    /** The actions: left Don, right Don, left Ka, right Ka. */
    private const MAX_ACTION = 3;

    /** How far from the song's start an input may be: a day, far past any song (ticks). */
    private const MAX_TICKS = 864_000_000_000;

    /**
     * The replay to keep, compressed again so that only what was checked is stored (PHP reads one gzip member,
     * the scorer reads them all, so a later one could hide any amount of data), or null if it is malformed.
     */
    public static function canonical(string $base64): ?string
    {
        $compressed = base64_decode($base64, true);
        $replay = $compressed === false ? false : @gzdecode($compressed, self::MAX_BYTES);

        // gzdecode's limit is only a rough one: it stops at the first buffer past it.
        return $replay !== false && strlen($replay) <= self::MAX_BYTES && self::isWellFormed($replay) ? gzencode($replay) : null;
    }

    /** What identifies a checked replay's inputs. */
    public static function hash(string $canonical): string
    {
        return hash('sha256', (string) gzdecode($canonical));
    }

    /**
     * Tells each player their malformed uploads will not count.
     *
     * @param  array<int, int>  $refused  how many, by baid
     */
    public static function notifyRefused(array $refused, string $noun): void
    {
        Player::query()->whereIn('baid', array_keys($refused))->with('user')->get()->each(function (Player $player) use ($refused, $noun): void {
            $count = $refused[$player->baid];
            $player->user?->notify(new WaddamburoNotice(
                ($count === 1 ? "Your {$noun}" : "Your {$count} {$noun}s").' could not be read and will not count: its replay is malformed.',
                'warning', 'play_rejected'));
        });
    }

    /** Inputs are in time order, as the game's clock only moves on, though the first can come before the song. */
    private static function isWellFormed(string $replay): bool
    {
        if ($replay === '' || ord($replay[0]) !== self::FORMAT) {
            return false;
        }
        $position = 1;
        $count = self::readVarint($replay, $position);
        // An input takes at least two bytes.
        if ($count === null || $count < 0 || $count > self::MAX_BYTES / 2) {
            return false;
        }
        $ticks = 0;
        for ($input = 0; $input < $count; $input++) {
            $delta = self::readVarint($replay, $position);
            if ($delta === null || ($input > 0 && $delta < 0) || abs($delta) > self::MAX_TICKS
                || abs($ticks += $delta) > self::MAX_TICKS
                || ! isset($replay[$position]) || ord($replay[$position++]) > self::MAX_ACTION) {
                return false;
            }
        }

        return $position === strlen($replay);
    }

    /** One 7-bit-encoded value (.NET's BinaryWriter): at most 10 bytes, the last holding the sign bit. */
    private static function readVarint(string $bytes, int &$position): ?int
    {
        $value = 0;
        for ($index = 0; $index < 10; $index++) {
            if (! isset($bytes[$position])) {
                return null;
            }
            $byte = ord($bytes[$position++]);
            // Nothing the game writes: a padded value would give the same inputs another hash.
            if (($index === 9 && $byte > 1) || ($index > 0 && $byte === 0)) {
                return null;
            }
            $value |= ($byte & 0x7F) << (7 * $index);
            if ($byte < 0x80) {
                return $value;
            }
        }

        return null;
    }
}
