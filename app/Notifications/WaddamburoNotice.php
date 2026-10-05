<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * A message for one player in Waddamburo (a rejected play, custom charts disabled, ...): stored until
 * the client marks it read, and pushed to the player's private channel (App.Models.User.{id}) when
 * they are connected.
 */
class WaddamburoNotice extends Notification
{
    /** @param  string  $kind  what it is about, for the client (e.g. play_rejected); free text otherwise */
    public function __construct(
        public string $message,
        public string $severity = 'info',
        public string $kind = 'message',
        public array $details = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message, 'severity' => $this->severity, 'kind' => $this->kind, 'details' => $this->details];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage(['id' => $this->id, ...$this->toArray($notifiable)]))->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return 'notice';
    }
}
