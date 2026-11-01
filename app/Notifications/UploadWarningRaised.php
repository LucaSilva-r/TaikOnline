<?php

namespace App\Notifications;

use App\Http\Middleware\ResolveTaikoVersion;
use App\Models\WdbUploadWarning;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Tells staff (on the site, live) a player sent bad data, the first time that day for that player and kind. */
class UploadWarningRaised extends Notification
{
    public function __construct(public WdbUploadWarning $warning) {}

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
        $player = $this->warning->player;
        $name = $player?->user?->name ?? $player?->mydon_name ?? 'A cabinet or account without a Banapass';

        return [
            'message' => "{$name}: {$this->warning->kind->label()}.",
            'severity' => 'warning',
            'kind' => 'upload_warning',
            'url' => route('mod.upload-warnings.index', ['taikoVersion' => ResolveTaikoVersion::DefaultScope], false),
        ];
    }
}
