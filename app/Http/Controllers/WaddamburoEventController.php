<?php

namespace App\Http\Controllers;

use App\Enums\TaikoGameVersion;
use App\Events\WdbProfileChanged;
use App\Http\Controllers\Settings\CostumeController;
use App\Models\Player;
use App\Models\Song;
use App\Services\WaddamburoEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Waddamburo's events (Green's collab campaigns in rotation): see WaddamburoEvents. */
class WaddamburoEventController extends Controller
{
    /** The running event for the game's event folder: its name, period and songs (musicids). Public: the same for everyone. */
    public function current(WaddamburoEvents $events): JsonResponse
    {
        return response()->json($events->current());
    }

    /** The exchange page: the running event, the player's tickets, its puchi and songs. */
    public function index(Request $request, WaddamburoEvents $events, WaddamburoWebController $web): Response
    {
        abort_unless($request->attributes->get('taikoVersionIsWaddamburo') === true, 404);
        $player = Player::query()->where('user_id', $request->user()->id)->first();
        $now = $events->at();
        $campaign = $events->campaign($now['index']);
        $owned = $player === null ? [] : array_flip($events->owned($player->baid));
        $songs = $events->songs($now['index']);
        $titles = Song::query()->where('version', TaikoGameVersion::Green->value)->whereIn('music_id', array_values($campaign['plays'] ?? []))
            ->pluck('title', 'music_id')->all();

        return Inertia::render('WaddamburoEvents', [
            'hasPlayer' => $player !== null,
            'event' => $events->current(),
            'tickets' => $player === null ? 0 : $events->tickets($player->baid, $now['index']),
            'puchi' => array_map(fn (int $id): array => ['id' => $id, 'name' => WaddamburoEvents::NAMES[$id] ?? '',
                'price' => $events->price($id), 'owned' => isset($owned[$id])], $campaign['puchi']),
            'plays' => array_map(fn (int $id, string $musicId): array => ['id' => $id, 'name' => WaddamburoEvents::NAMES[$id] ?? '',
                'song' => $titles[$musicId] ?? $musicId, 'owned' => isset($owned[$id])],
                array_keys($campaign['plays'] ?? []), array_values($campaign['plays'] ?? [])),
            // As the song list shows them (each links to its page).
            'songs' => $web->stockSongCards($request, $songs),
            'sheet' => CostumeController::spritesheet(TaikoGameVersion::Green),
        ]);
    }

    public function purchase(Request $request, WaddamburoEvents $events): RedirectResponse
    {
        abort_unless($request->attributes->get('taikoVersionIsWaddamburo') === true, 404);
        $player = Player::query()->where('user_id', $request->user()->id)->firstOrFail();
        $input = $request->validate(['id' => ['required', 'integer']]);

        $result = $events->buy($player, (int) $input['id']);
        if ($result === 'ok') {
            // The games signed in as this player re-read the profile: what was bought unlocks.
            WdbProfileChanged::dispatch($player->refresh());
        }
        Inertia::flash('toast', match ($result) {
            'ok' => ['type' => 'success', 'message' => __('Exchanged! It is unlocked in Waddamburo.')],
            'owned' => ['type' => 'error', 'message' => __('You already have it.')],
            'short' => ['type' => 'error', 'message' => __('Not enough tickets.')],
            default => ['type' => 'error', 'message' => __('That is not exchanged in this event.')],
        });

        return back();
    }
}
