<?php

namespace App\Services;

use App\Enums\SanctionType;
use App\Models\BlockedEmail;
use App\Models\Player;
use App\Models\PlayerVersionStats;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbDanPlay;
use App\Models\WdbDanPlayReport;
use App\Models\WdbPlay;
use App\Models\WdbUploadWarning;
use App\Notifications\DanPlayReported;
use App\Notifications\PlayReported;
use App\Notifications\UploadWarningRaised;
use App\Notifications\WaddamburoNotice;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes an account and what is about its owner: the player and all their plays, cards and progress
 * (foreign keys cascade from the player), and what no foreign key reaches. The moderation log stays, with the
 * player unlinked: it records what staff did and why. A BAID never linked to an account is not touched.
 * Deleting while banned leaves a hash of the email (BlockedEmail), so it cannot sign up again until the ban ends.
 */
class AccountDeletion
{
    public function delete(User $user): void
    {
        $name = $user->name;
        $email = $user->email;
        $players = Player::query()->where('user_id', $user->id)->get();

        DB::transaction(function () use ($user, $name, $email, $players): void {
            foreach ($players as $player) {
                $ban = Sanction::activeFor((int) $player->baid, SanctionType::Ban);
                if ($ban !== null) {
                    BlockedEmail::block($email, $ban->expires_at);
                }
                $this->removeStaffNotificationsAbout($player);
                PlayerVersionStats::query()->where('baid', $player->baid)->delete();
                WdbUploadWarning::query()->where('baid', $player->baid)->delete();
                $player->delete();
            }
            PlayerVersionStats::query()->where('user_id', $user->id)->delete();
            // Their other reports cascade; these would stay with the reporter unlinked, comment and all.
            WdbDanPlayReport::query()->where('reporter_id', $user->id)->delete();
            $this->removeOutcomesNaming($name);
            $user->tokens()->delete();
            $user->notifications()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            $user->delete();
        });

        Storage::disk('public')->delete("avatars/{$user->id}.png");
    }

    /** Staff's notifications about this player's reported plays and upload warnings. */
    private function removeStaffNotificationsAbout(Player $player): void
    {
        $about = function (string $type, iterable $fragments): void {
            foreach ($fragments as $fragment) {
                DatabaseNotification::query()->where('type', $type)->where('data', 'like', "%{$fragment}%")->delete();
            }
        };
        $about(PlayReported::class, WdbPlay::query()->where('baid', $player->baid)->pluck('id'));
        $about(DanPlayReported::class, WdbDanPlay::query()->where('baid', $player->baid)->pluck('id'));
        $about(UploadWarningRaised::class, WdbUploadWarning::query()->where('baid', $player->baid)->pluck('id')
            ->map(fn (int $id): string => '"warning_id":'.$id.','));
    }

    /** What reporters were told about this player names them (ModerationService::resolve). */
    private function removeOutcomesNaming(string $name): void
    {
        DatabaseNotification::query()->where('type', WaddamburoNotice::class)->where('data', 'like', '%"kind":"report_resolved"%')->get()
            ->filter(fn (DatabaseNotification $notification): bool => str_contains((string) ($notification->data['message'] ?? ''), $name))
            ->each->delete();
    }
}
