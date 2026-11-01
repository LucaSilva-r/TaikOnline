<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A player's Waddamburo status reaching anyone watching their board (public, like the board itself). */
class WdbPlayerStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  array{state: string, song: array{title: string, course: int}|null}  $status */
    public function __construct(public int $userId, public array $status) {}

    public function broadcastOn(): Channel
    {
        return new Channel("wdb.status.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'status';
    }

    /** @return array{state: string, song: array{title: string, course: int}|null} */
    public function broadcastWith(): array
    {
        return $this->status;
    }
}
