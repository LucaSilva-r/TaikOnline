<?php

namespace App\Console\Commands;

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class ImportSongsCommand extends Command
{
    protected $signature = 'app:import-songs {version}';

    protected $description = 'Import songs from a musicinfo.xml file for the given version';

    public function handle(): int
    {
        $version = $this->argument('version');
        $dataPath = Config::get('taiko_green.data_path', storage_path('app/game-data'));

        $xmlPath = "{$dataPath}/{$version}/musicinfo.xml";
        if (! file_exists($xmlPath)) {
            $this->error("File not found: {$xmlPath}");

            return self::FAILURE;
        }

        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            $this->error("Failed to parse XML file: {$xmlPath}");

            return self::FAILURE;
        }

        /** @var \SimpleXMLElement[] $dataElements */
        $musicInfo = $xml->MusicInfo;
        $dataElements = $musicInfo?->Data ?? [];
        $total = count($dataElements);
        $this->info("Found {$total} songs in {$xmlPath}");

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($dataElements as $index => $songData) {
            $musicId = (string) $songData->musicid;
            $uniqueId = (int) $songData->uniqueid;
            $title = (string) $songData->musicname;
            $genreName = (string) $songData->genrename;
            $partsSetName = (string) $songData->partsset;
            $wai2PartsSetName = (string) $songData->wai2partsset;
            $newRelease = (int) $songData->newrelease;
            $secret = (int) $songData->secret;
            $papamama = (int) $songData->papamama;
            $hasextreme = (int) $songData->hasextreme;
            $demoplay = (int) $songData->demoplay;

            $tags = [];
            foreach ($songData->tag as $tagValue) {
                $tags[] = (int) $tagValue;
            }

            // Pad to 16 tags if needed
            while (count($tags) < 16) {
                $tags[] = 0;
            }

            try {
                $genreEnum = SongGenre::fromXml($genreName);

                $partsSetEnum = SongPartsSet::tryFrom($partsSetName);
                if ($partsSetEnum === null) {
                    $this->warn("[{$index}] Unknown partsset '{$partsSetName}' for song {$musicId}");
                    $errors++;

                    continue;
                }

                $wai2PartsSetEnum = SongWai2PartsSet::tryFrom($wai2PartsSetName);
                if ($wai2PartsSetEnum === null) {
                    $this->warn("[{$index}] Unknown wai2partsset '{$wai2PartsSetName}' for song {$musicId}");
                    $errors++;

                    continue;
                }

                $record = Song::where('version', $version)
                    ->where('music_id', $musicId)
                    ->first();

                if ($record === null) {
                    Song::create([
                        'version' => $version,
                        'song_no' => $uniqueId,
                        'music_id' => $musicId,
                        'unique_id' => $uniqueId,
                        'title' => $title,
                        'title_en' => null,
                        'genre' => $genreEnum->value,
                        'partsset' => $partsSetEnum->value,
                        'wai2_partsset' => $wai2PartsSetEnum->value,
                        'flags' => [
                            'hasextreme' => $hasextreme === 1,
                            'papamama' => $papamama === 1,
                            'secret' => $secret === 1,
                            'newrelease' => $newRelease === 1,
                            'demoplay' => $demoplay === 1,
                        ],
                        'tags' => $tags,
                    ]);
                    $created++;
                } else {
                    $record->update([
                        'song_no' => $uniqueId,
                        'unique_id' => $uniqueId,
                        'title' => $title,
                        'title_en' => null,
                        'genre' => $genreEnum->value,
                        'partsset' => $partsSetEnum->value,
                        'wai2_partsset' => $wai2PartsSetEnum->value,
                        'flags' => [
                            'hasextreme' => $hasextreme === 1,
                            'papamama' => $papamama === 1,
                            'secret' => $secret === 1,
                            'newrelease' => $newRelease === 1,
                            'demoplay' => $demoplay === 1,
                        ],
                        'tags' => $tags,
                    ]);
                    $updated++;
                }
            } catch (\Throwable $e) {
                if ($e instanceof \InvalidArgumentException && str_contains($e->getMessage(), 'Unknown genre')) {
                    $this->warn("[{$index}] Unknown genre '{$genreName}' for song {$musicId}");
                    $errors++;

                    continue;
                }

                $this->error("[{$index}] Failed to import '{$musicId}': {$e->getMessage()}");
                $errors++;
            }
        }

        $currentCount = Song::where('version', $version)->count();

        $this->newLine();
        $this->info("Import complete for version '{$version}':");
        $this->line("  Total songs in file: {$total}");
        $this->line("  Songs created:       {$created}");
        $this->line("  Songs updated:       {$updated}");
        $this->line("  Errors:              {$errors}");

        if ($errors > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
