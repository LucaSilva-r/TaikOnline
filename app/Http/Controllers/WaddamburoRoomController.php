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
    public function index(Request $request, WaddamburoRooms $rooms): JsonResponse
    {
        abort_if($request->user() === null, 404);

        return response()->json(['rooms' => $rooms->listed()]);
    }

    public function store(Request $request, WaddamburoRooms $rooms): JsonResponse
    {
        $user = $this->player($request, $rooms);
        $validated = $request->validate($this->settingsRules());
        $code = $rooms->create($user, $validated['name'] ?? null, (bool) ($validated['is_public'] ?? true), $validated['password'] ?? null);

        return response()->json($this->room($code, $rooms, $request->user()), 201);
    }

    public function update(Request $request, string $code, WaddamburoRooms $rooms): JsonResponse
    {
        $code = strtoupper($code);
        abort_if($request->user() === null, 404);
        abort_unless($rooms->update($code, $request->user(), $request->validate($this->settingsRules())), 404);

        return response()->json($this->room($code, $rooms, $request->user()));
    }

    public function quickPlay(Request $request, WaddamburoRooms $rooms): JsonResponse
    {
        return response()->json($this->room($rooms->quickPlay($user = $this->player($request, $rooms)), $rooms, $user));
    }

    public function keepAlive(Request $request, string $code, WaddamburoRooms $rooms): Response
    {
        abort_unless($request->user() !== null && $rooms->keepAlive(strtoupper($code), $request->user()), 404);

        return response()->noContent();
    }

    public function join(Request $request, string $code, WaddamburoRooms $rooms): JsonResponse
    {
        $code = strtoupper($code);
        $password = $request->validate(['password' => ['nullable', 'string', 'max:64']])['password'] ?? null;
        $result = $rooms->join($code, $this->player($request, $rooms), $password);
        abort_if($result === WaddamburoRooms::NO_ROOM, 404);
        if ($result === WaddamburoRooms::WRONG_PASSWORD) {
            return response()->json(['message' => 'Wrong password.', 'wrong_password' => true], 403);
        }

        return response()->json($this->room($code, $rooms, $request->user()));
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

    /** @return array<string, list<string>> */
    private function settingsRules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_public' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    private function player(Request $request, WaddamburoRooms $rooms): User
    {
        $user = $request->user();
        abort_if($user === null, 404);
        abort_unless($rooms->mayPlay($user), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function room(string $code, WaddamburoRooms $rooms, User $user): array
    {
        return [...($rooms->describe($code) ?? ['code' => $code]), 'channel' => "presence-wdb.room.{$code}", 'is_host' => $rooms->isHost($code, $user)];
    }
}
