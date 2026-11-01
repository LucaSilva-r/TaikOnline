<?php

namespace App\Jobs;

use App\Models\WdbPlay;
use App\Services\WaddamburoAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Analyzes plays just scored, off the request that uploaded them. */
class AnalyzeWaddamburoPlays implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $playIds */
    public function __construct(public array $playIds) {}

    public function handle(WaddamburoAnalyzer $analyzer): void
    {
        $analyzer->analyze(WdbPlay::query()->whereKey($this->playIds)->get());
    }
}
