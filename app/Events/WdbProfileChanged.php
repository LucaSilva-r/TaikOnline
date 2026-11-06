<?php

namespace App\Events;

use App\Http\Controllers\WaddamburoController;
use App\Models\Player;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A player's Waddamburo loadout changed on the website (costume, colours, title, displayed dan rank):
 * their clients get the new profile, as api/wdb/me serves it, on the account's private channel and
 * show it from the next scene on. Queued, so a realtime outage never fails the save.
 */
class WdbProfileChanged implements ShouldBroadcast
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(public Player $player) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->player->user_id);
    }

    public function broadcastAs(): string
    {
        return 'profile';
    }

    public function broadcastWith(): array
    {
        return WaddamburoController::profile($this->player->fresh() ?? $this->player);
    }

    public function broadcastWhen(): bool
    {
        return $this->player->user_id !== null;
    }
}
