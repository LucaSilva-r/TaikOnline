<?php

namespace App\Console\Commands;

use App\Models\Song;
use App\Support\SongSearch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('app:rebuild-song-search-index')]
#[Description('Recompute the searchable romaji/title index for every song')]
class RebuildSongSearchIndexCommand extends Command
{
    public function handle(): int
    {
        $updated = 0;

        Song::query()->chunkById(500, function (Collection $songs) use (&$updated): void {
            foreach ($songs as $song) {
                $song->update([
                    'search_index' => SongSearch::indexFor($song->title),
                ]);
                $updated++;
            }
        });

        $this->info("Rebuilt search index for {$updated} songs.");

        return self::SUCCESS;
    }
}
