<?php

use App\Models\User;
use App\Services\WaddamburoRooms;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// An online room: its two players set up their peer-to-peer connection here, in client events. Games only.
Broadcast::channel('wdb.room.{code}', function (User $user, string $code) {
    if (! $user->tokenCan('wdb') || ! app(WaddamburoRooms::class)->isMember($code, $user)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'baid' => (int) $user->player?->baid];
});
