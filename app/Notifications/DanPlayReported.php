<?php

namespace App\Notifications;

use App\Models\WdbDanCourse;
use App\Models\WdbDanPlayReport;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Tells staff a dan run entered the moderation queue. */
class DanPlayReported extends Notification
{
    public function __construct(public WdbDanPlayReport $report) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage(['id' => $this->id, ...$this->toArray($notifiable)]))->onConnection('sync');
    }

    /** Not "notice": Waddamburo ignores it, the site's notification center shows it. */
    public function broadcastAs(): string
    {
        return 'site-notice';
    }

    public function toArray(object $notifiable): array
    {
        $play = $this->report->play;
        $player = $play->player?->user?->name ?? $play->player?->mydon_name ?? 'A player';
        $course = WdbDanCourse::query()->where('content_hash', $play->content_hash)->value('name') ?? 'a course';

        return [
            'message' => "{$player}'s dan run of {$course} was reported: {$this->report->reason->label()}.",
            'severity' => 'info',
            'kind' => 'play_reported',
            'url' => route('dan-plays.show', ['taikoVersion' => 'waddamburo', 'id' => $play->id], false),
        ];
    }
}
