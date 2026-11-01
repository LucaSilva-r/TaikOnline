<?php

namespace App\Services;

use App\Enums\PresenceStatus;
use App\Enums\SanctionType;
use App\Events\WdbPlayerStatusChanged;
use App\Http\Controllers\LegalController;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbChart;
use Illuminate\Support\Facades\Cache;

/**
 * What a home player is doing in Waddamburo, as the website shows it. The game reports it over HTTP (Laravel
 * never sees Reverb's client events and Reverb has no webhooks); it lives only in the status cache store and
 * goes when the game stops reporting. Valkey being down costs the status, never the page or the request.
 */
class WaddamburoStatus
{
    /** The game reports every 30 s, so this outlasts two lost reports. The board goes offline on the same limit. */
    public const TTL_SECONDS = 90;

    /**
     * Stores the player's status and pushes it, unless they chose to be invisible: then only the server knows.
     * A banned player, or one who hasn't accepted the current Rules and Privacy notice, isn't kept at all. The
     * game can't tell and needn't care.
     */
    public function report(User $user, string $state, ?string $chartSha256): void
    {
        $baid = $user->player?->baid;
        if ($baid === null || LegalController::mustAccept($user) || Sanction::activeFor((int) $baid, SanctionType::Ban) !== null) {
            return;
        }
        $status = ['state' => $state, 'song' => $state === 'playing' && $chartSha256 !== null ? $this->song($chartSha256) : null];
        rescue(fn () => Cache::store('status')->put($this->key($user), $status, self::TTL_SECONDS), report: false);
        if (! $user->presence_status->hidesStatus()) {
            rescue(fn () => WdbPlayerStatusChanged::dispatch($user->id, $status), report: false);
        }
    }

    /** Hides or shows the player from now on, without waiting for the game's next report. */
    public function setPresenceStatus(User $user, PresenceStatus $presenceStatus): void
    {
        $user->update(['presence_status' => $presenceStatus]);
        $status = $presenceStatus->hidesStatus() ? ['state' => 'offline', 'song' => null] : $this->of($user);
        if ($status !== null) {
            rescue(fn () => WdbPlayerStatusChanged::dispatch($user->id, $status), report: false);
        }
    }

    /** The player left the game. */
    public function clear(User $user): void
    {
        rescue(fn () => Cache::store('status')->forget($this->key($user)), report: false);
        rescue(fn () => WdbPlayerStatusChanged::dispatch($user->id, ['state' => 'offline', 'song' => null]), report: false);
    }

    /**
     * What everyone else may see: nothing while the player is invisible.
     *
     * @return array{state: string, song: array{title: string, course: int}|null}|null
     */
    public function of(User $user): ?array
    {
        if ($user->presence_status->hidesStatus()) {
            return null;
        }

        return rescue(fn () => Cache::store('status')->get($this->key($user)), report: false);
    }

    /**
     * The song's name, only for a chart that's public anyway: ranked or legacy, or an osu! beatmap. Other custom
     * charts stay with their communities (WdbChart::visibleTo), and the server may not know the chart yet.
     *
     * @return array{title: string, course: int}|null
     */
    private function song(string $chartSha256): ?array
    {
        $chart = WdbChart::query()->where('sha256', $chartSha256)
            ->where(fn ($visible) => $visible->whereNotNull('ranked_at')->orWhereNotNull('legacy_at')->orWhereNotNull('osu_beatmapset_id'))
            ->first(['title', 'title_en', 'course']);

        return $chart === null ? null : ['title' => (string) ($chart->title ?? $chart->title_en), 'course' => (int) $chart->course];
    }

    private function key(User $user): string
    {
        return "wdb-status:{$user->id}";
    }
}
