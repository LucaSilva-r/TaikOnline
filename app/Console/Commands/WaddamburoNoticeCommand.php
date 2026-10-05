<?php

namespace App\Console\Commands;

use App\Events\WdbNoticePosted;
use App\Models\User;
use App\Models\WdbNotice;
use App\Notifications\WaddamburoNotice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:wdb-notice {message : The text Waddamburo shows}
    {--severity=info : info, warning or critical}
    {--minutes= : Show it this many minutes, then stop}
    {--user= : Send it to this user (id or e-mail) only, not every client}')]
#[Description('Send a notice to every Waddamburo client, or to one player')]
class WaddamburoNoticeCommand extends Command
{
    public function handle(): int
    {
        $message = (string) $this->argument('message');
        $severity = (string) $this->option('severity');
        if (! in_array($severity, WdbNotice::SEVERITIES, true)) {
            $this->error('Severity must be one of: '.implode(', ', WdbNotice::SEVERITIES).'.');

            return self::FAILURE;
        }

        if (($target = $this->option('user')) !== null) {
            $user = User::query()->where('email', $target)->orWhere('id', ctype_digit((string) $target) ? (int) $target : 0)->first();
            if ($user === null) {
                $this->error("No user {$target}.");

                return self::FAILURE;
            }
            $user->notify(new WaddamburoNotice($message, $severity));
            $this->info("Sent to {$user->name}.");

            return self::SUCCESS;
        }

        $minutes = $this->option('minutes');
        $notice = WdbNotice::query()->create([
            'message' => $message, 'severity' => $severity,
            'ends_at' => $minutes === null ? null : now()->addMinutes((int) $minutes),
        ]);
        WdbNoticePosted::dispatch($notice);
        $this->info("Notice {$notice->id} sent to every client.");

        return self::SUCCESS;
    }
}
