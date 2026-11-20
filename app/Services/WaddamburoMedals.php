<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\ItemShopCatalog;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\PlayerShopItem;
use App\Models\WdbMedalCredit;
use App\Models\WdbMedalSong;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Waddamburo's Don Medals, paid like Green's: a fixed amount per credit, whatever the songs' results.
 * A credit is an arcade credit (its id), a dan course, or three songs at home. Green's bonus rule:
 * daily/weekly/monthly when the player's previous credit was before today/this week/this month.
 */
class WaddamburoMedals
{
    /** Green's songs per credit, also a home credit's size. */
    public const HOME_CREDIT_SONGS = 3;

    /**
     * Counts the finished songs (already-known ids are skipped) and pays the credits they complete.
     *
     * @param  list<array{id: string, played_at: string, credit_id?: ?string, kind: string}>  $songs
     * @return list<string> the song ids taken (new and already known)
     */
    public function record(int $baid, array $songs): array
    {
        return DB::transaction(function () use ($baid, $songs): array {
            $player = Player::query()->lockForUpdate()->findOrFail($baid);
            $taken = [];
            foreach ($songs as $song) {
                $taken[] = $song['id'];
                if (WdbMedalSong::query()->whereKey($song['id'])->exists()) {
                    continue;
                }
                // The client's own offset decides its calendar day.
                $playedAt = CarbonImmutable::parse($song['played_at']);
                $creditId = $song['credit_id'] ?? null;
                $newCredit = $creditId !== null
                    && ! WdbMedalSong::query()->where('baid', $baid)->where('credit_id', $creditId)->exists();
                WdbMedalSong::query()->create([
                    'id' => $song['id'],
                    'baid' => $baid,
                    'credit_id' => $creditId,
                    'kind' => $song['kind'],
                    'chart_sha256' => $song['chart_sha256'] ?? null,
                    'side' => (int) ($song['side'] ?? 0),
                    'select_seconds' => $song['select_seconds'] ?? null,
                    'local_day' => $playedAt->toDateString(),
                    'played_at' => $playedAt,
                ]);

                $pays = match (true) {
                    $song['kind'] === 'dan' => true,
                    $creditId !== null => $newCredit,
                    default => WdbMedalSong::query()->where('baid', $baid)->whereNull('credit_id')
                        ->where('kind', 'song')->count() % self::HOME_CREDIT_SONGS === 0,
                };
                if ($pays) {
                    $this->pay($player, $playedAt->startOfDay());
                    // Condition titles, as Green checks them at a credit's end (a dan course gives none): a home credit is
                    // complete now; an arcade one when the next one starts (so the one before this).
                    if ($song['kind'] === 'song' && ($previous = $creditId === null ? null : $this->previousCredit($baid, $creditId)) !== false) {
                        app(WaddamburoTitles::class)->checkCredit($player, $this->creditSongs($baid, $previous ?? $creditId));
                    }
                }
            }

            return $taken;
        });
    }

    /**
     * The credits paid since the client last asked, marked announced.
     *
     * @return list<array{medals: int, daily: bool, weekly: bool, monthly: bool}>
     */
    public function announce(int $baid): array
    {
        $credits = WdbMedalCredit::query()->where('baid', $baid)->where('announced', false)->orderBy('id')->get();
        WdbMedalCredit::query()->whereKey($credits->modelKeys())->update(['announced' => true]);

        return $credits->map(fn (WdbMedalCredit $credit): array => [
            'medals' => $credit->medals,
            'daily' => $credit->daily,
            'weekly' => $credit->weekly,
            'monthly' => $credit->monthly,
        ])->all();
    }

    /** Medals to spend: everything earned minus the Waddamburo shop's prices. */
    /** This season's medals: Green's wallet starts again at zero when the shop's lineup changes. */
    public function wallet(int $baid): int
    {
        $start = $this->seasonStart();

        return (int) WdbMedalCredit::query()->where('baid', $baid)->where('local_day', '>=', $start->toDateString())->sum('medals')
            - (int) PlayerShopItem::query()->where('baid', $baid)->where('game_version', PlayerCosmetic::WADDAMBURO)
                ->where('created_at', '>=', $start)->sum('item_price');
    }

    /** The day the active shop season started (its dates moved into the current cycle by ItemShopCatalog). */
    public function seasonStart(): CarbonImmutable
    {
        $season = (new ItemShopCatalog(TaikoGameVersion::Green, 0))->getActiveSeason();

        return $season === null ? CarbonImmutable::createFromTimestamp(0)
            : CarbonImmutable::createFromFormat('YmdHis', $season['start_datetime'])->startOfDay();
    }

    /**
     * The arcade credit played before <paramref name="creditId"/> (false: none).
     *
     * ponytail: an arcade credit's titles wait for the next credit; showing them at the credit's end (Green's medal screen)
     * needs the client to report the end.
     */
    private function previousCredit(int $baid, string $creditId): string|false
    {
        return WdbMedalSong::query()->where('baid', $baid)->where('kind', 'song')->whereNotNull('credit_id')
            ->where('credit_id', '!=', $creditId)->orderByDesc('played_at')->value('credit_id') ?? false;
    }

    /**
     * A credit's songs: an arcade credit's by its id, at home the last three.
     *
     * @return list<WdbMedalSong>
     */
    private function creditSongs(int $baid, ?string $creditId): array
    {
        $songs = WdbMedalSong::query()->where('baid', $baid)->where('kind', 'song');

        return ($creditId !== null ? $songs->where('credit_id', $creditId)->orderBy('played_at')
            : $songs->whereNull('credit_id')->orderByDesc('played_at')->orderByDesc('created_at')->limit(self::HOME_CREDIT_SONGS))
            ->get()->sortBy('played_at')->values()->all();
    }

    private function pay(Player $player, CarbonImmutable $day): void
    {
        $last = WdbMedalCredit::query()->where('baid', $player->baid)->max('local_day');
        $last = $last === null ? null : CarbonImmutable::parse($last);
        // ponytail: weeks start on Monday; Green's own week start is not known.
        // Green: a last credit from before this season pays every bonus (research/ghidra/rewards-green.md).
        $fresh = $last === null || $last < $this->seasonStart();
        $daily = $fresh || $last < $day;
        $weekly = $fresh || $last < $day->startOfWeek(CarbonImmutable::MONDAY);
        $monthly = $fresh || $last < $day->startOfMonth();
        $medals = 100 + ($daily ? 50 : 0) + ($weekly ? 100 : 0) + ($monthly ? 300 : 0);

        WdbMedalCredit::query()->create([
            'baid' => $player->baid,
            'local_day' => $day->toDateString(),
            'season_id' => (new ItemShopCatalog(TaikoGameVersion::Green, 0))->activeSeasonId ?? 0,
            'medals' => $medals,
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly,
        ]);
        $player->total_get_donmedal = (int) $player->total_get_donmedal + $medals;
        $player->save();
    }
}
