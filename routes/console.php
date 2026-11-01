<?php

use App\Models\BlockedEmail;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:record-player-rank-snapshots')
    ->daily()
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => [BlockedEmail::class]])->daily();
