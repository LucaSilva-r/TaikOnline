<?php

namespace App\Events;

use App\Models\WdbNotice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A system notice reaching every connected Waddamburo client at once (public channel wdb.notices). */
class WdbNoticePosted implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public WdbNotice $notice) {}

    public function broadcastOn(): Channel
    {
        return new Channel('wdb.notices');
    }

    public function broadcastAs(): string
    {
        return 'notice';
    }

    public function broadcastWith(): array
    {
        return $this->notice->toClient();
    }
}
