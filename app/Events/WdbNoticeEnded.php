<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A system notice ended early or deleted: connected Waddamburo clients take it off their board. */
class WdbNoticeEnded implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $noticeId) {}

    public function broadcastOn(): Channel
    {
        return new Channel('wdb.notices');
    }

    public function broadcastAs(): string
    {
        return 'notice-ended';
    }

    public function broadcastWith(): array
    {
        return ['id' => (string) $this->noticeId];
    }
}
