<?php

namespace App\Notifications;

use App\Models\PlayReport;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Tells staff (on the site, live) a play went into the reports queue (its first open report; later ones add to the same entry). */
class PlayReported extends Notification
{
    public function __construct(public PlayReport $report) {}

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
        $chart = $play->chart?->title_en ?? $play->chart?->title ?? 'a chart';

        return [
            'message' => "{$player}'s play of {$chart} was reported: {$this->report->reason->label()}.",
            'severity' => 'info',
            'kind' => 'play_reported',
            'url' => route('plays.show', ['taikoVersion' => 'waddamburo', 'id' => $play->id], false),
        ];
    }
}
