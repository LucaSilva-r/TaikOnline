<?php

namespace App\Http\Controllers;

use App\Models\WdbNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Waddamburo's realtime messages. Clients connect to Reverb (Pusher protocol) for pushes: system
 * notices on the public channel wdb.notices, a player's own on private-App.Models.User.{id}. These
 * endpoints give the connection details and what was missed while offline.
 */
class WaddamburoNoticeController extends Controller
{
    /** Where to connect: the Pusher-protocol WebSocket URL and app key. */
    public function realtime(): JsonResponse
    {
        $key = config('broadcasting.connections.reverb.key');
        $base = rtrim((string) config('broadcasting.connections.reverb.public_url'), '/');

        return response()->json([
            'url' => $key === null || $base === '' ? null : "{$base}/app/{$key}",
            'key' => $key,
        ]);
    }

    /** The system notices showing now. */
    public function notices(): JsonResponse
    {
        return response()->json([
            'notices' => WdbNotice::query()->active()->latest('id')->limit(20)->get()->map->toClient()->all(),
        ]);
    }

    /** The player's unread notices, oldest first, and the private channel their new ones arrive on. */
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'channel' => $user === null ? null : 'private-'.$user->receivesBroadcastNotificationsOn(),
            'notifications' => $user === null ? [] : $user->unreadNotifications()->oldest()->limit(50)->get()
                ->map(fn ($notification): array => [
                    'id' => $notification->id, ...$notification->data,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    /** Marks the player's notices read (they were shown). */
    public function read(Request $request): Response
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['string', 'uuid']])['ids'];
        $request->user()?->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => now()]);

        return response()->noContent();
    }
}
