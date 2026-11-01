<?php

namespace App\Services;

use App\Enums\UploadWarningKind;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\WdbUploadWarning;
use App\Notifications\UploadWarningRaised;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Notes bad uploads for the moderators' page. Staff hear of a player and kind once a day, so a flood stays quiet. */
class WaddamburoUploadWarnings
{
    public static function record(?int $baid, UploadWarningKind $kind, ?string $playId = null, ?string $detail = null): void
    {
        $warning = WdbUploadWarning::query()->firstOrCreate(
            ['baid' => $baid, 'kind' => $kind, 'day' => now()->toDateString()],
            ['last_seen_at' => now()],
        );
        $warning->increment('count', 1, [
            'play_id' => $playId,
            'detail' => $detail === null ? null : Str::limit($detail, 250),
            'last_seen_at' => now(),
        ]);
        if ($warning->wasRecentlyCreated) {
            Notification::send(User::query()->whereIn('role', [UserRole::Admin, UserRole::Moderator])->get(), new UploadWarningRaised($warning));
        }
    }
}
