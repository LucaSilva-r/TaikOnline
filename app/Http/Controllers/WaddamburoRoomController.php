<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\WaddamburoRooms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Waddamburo's online rooms, for home players. A cabinet isn't anyone in particular: it gets a 404. */
class WaddamburoRoomController extends Controller
{
    public function store(Request $request, WaddamburoRooms $rooms): JsonResponse
    {
        $user = $this->player($request, $rooms);
        $code = $rooms->create($user);

        return response()->json($this->room($code), 201);
    }

    public function join(Request $request, string $code, WaddamburoRooms $rooms): JsonResponse
    {
        $code = strtoupper($code);
        abort_unless($rooms->join($code, $this->player($request, $rooms)), 404);

        return response()->json($this->room($code));
    }

    public function destroy(Request $request, string $code, WaddamburoRooms $rooms): Response
    {
        abort_if($request->user() === null, 404);
        $rooms->leave(strtoupper($code), $request->user());

        return response()->noContent();
    }

    /** TURN credentials open the relay, so only a room's own players get them. */
    public function iceServers(Request $request, string $code, WaddamburoRooms $rooms): JsonResponse
    {
        abort_unless($request->user() !== null && $rooms->isMember(strtoupper($code), $request->user()), 404);

        return response()->json(['ice_servers' => $rooms->iceServers($request->user())]);
    }

    private function player(Request $request, WaddamburoRooms $rooms): User
    {
        $user = $request->user();
        abort_if($user === null, 404);
        abort_unless($rooms->mayPlay($user), 403);

        return $user;
    }

    /** @return array{code: string, channel: string} */
    private function room(string $code): array
    {
        return ['code' => $code, 'channel' => "presence-wdb.room.{$code}"];
    }
}
