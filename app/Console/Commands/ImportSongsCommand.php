<?php

namespace App\Console\Commands;

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Enums\TaikoGameVersion;
use App\Models\Song;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class ImportSongsCommand extends Command
{
    protected $signature = 'app:import-songs {version? : Game version (omit to import every version)} {--source= : Root of a PS3 game/ dump to copy musicinfo.xml from before importing}';

    protected $description = 'Import songs from musicinfo.xml for one or all Taiko versions';

    public function handle(): int
    {
        $source = $this->option('source');
        $versionArg = $this->argument('version');

        if ($versionArg !== null) {
            $version = TaikoGameVersion::fromInput((string) $versionArg);
            if (! $version instanceof TaikoGameVersion) {
                $this->error("Unknown game version: {$versionArg}");

                return self::FAILURE;
            }
            $versions = [$version];
        } else {
            $versions = TaikoGameVersion::cases();
        }

        $dataPath = (string) Config::get('taiko_green.data_path', storage_path('app/game-data'));
        $fatal = false;

        foreach ($versions as $version) {
            if ($source !== null && ! $this->syncFromSource((string) $source, $dataPath, $version)) {
                continue;
            }

            $counts = $this->importVersion($dataPath, $version);
            if ($counts === null) {
                // Missing/unreadable file is only fatal when the user asked for this one version.
                $fatal = $fatal || $versionArg !== null;

                continue;
            }

            $this->info(sprintf(
                '%s: %d created, %d updated, %d skipped (of %d)',
                $version->value,
                $counts['created'],
                $counts['updated'],
                $counts['skipped'],
                $counts['total'],
            ));
        }

        return $fatal ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copy <source>/<game-folder>/USRDIR/data/musicinfo.xml into the canonical
     * storage location for this version. Returns false (with a warning) when the
     * source cannot be located.
     */
    private function syncFromSource(string $source, string $dataPath, TaikoGameVersion $version): bool
    {
        $folder = $this->locateGameFolder($source, $version);
        if ($folder === null) {
            $this->warn("No game folder for {$version->value} under {$source}");

            return false;
        }

        $sourceFile = $this->bestMusicInfo($folder);
        if ($sourceFile === null) {
            $this->warn("No musicinfo.xml for {$version->value} under {$folder}");

            return false;
        }

        $targetDir = "{$dataPath}/{$version->value}";
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            $this->warn("Could not create {$targetDir}");

            return false;
        }

        copy($sourceFile, "{$targetDir}/musicinfo.xml");

        return true;
    }

    /**
     * The authoritative catalog for a version is the fullest musicinfo.xml in its
     * own game folder: the base USRDIR/data/musicinfo.xml is a reduced list, while
     * the per-board variant under config/<board>/ carries the complete catalog (and
     * some versions only ship one or the other). Picking the file with the most
     * <Data> entries selects the right one without mapping board ids.
     */
    private function bestMusicInfo(string $folder): ?string
    {
        $data = "{$folder}/USRDIR/data";
        $candidates = [];
        if (is_file("{$data}/musicinfo.xml")) {
            $candidates[] = "{$data}/musicinfo.xml";
        }
        if (is_dir("{$data}/config")) {
            foreach (scandir("{$data}/config") ?: [] as $board) {
                if ($board === '.' || $board === '..') {
                    continue;
                }
                $path = "{$data}/config/{$board}/musicinfo.xml";
                if (is_file($path)) {
                    $candidates[] = $path;
                }
            }
        }

        $best = null;
        $bestCount = -1;
        foreach ($candidates as $path) {
            $xml = @simplexml_load_file($path);
            $count = $xml === false ? 0 : count($xml->MusicInfo?->Data ?? []);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $path;
            }
        }

        return $best;
    }

    /**
     * Find the game-dump folder whose name contains this version's colour label
     * (e.g. "SCEEXE001 RED" for RED).
     */
    private function locateGameFolder(string $source, TaikoGameVersion $version): ?string
    {
        if (! is_dir($source)) {
            return null;
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$source}/{$entry}";
            if (is_dir($path) && stripos($entry, $version->label()) !== false) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array{total: int, created: int, updated: int, skipped: int}|null
     */
    private function importVersion(string $dataPath, TaikoGameVersion $version): ?array
    {
        $xmlPath = $this->musicInfoPath($dataPath, $version);
        if (! is_file($xmlPath)) {
            $this->warn("No musicinfo.xml for {$version->value} at {$xmlPath}");

            return null;
        }

        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            $this->warn("Failed to parse {$xmlPath}");

            return null;
        }

        /** @var \SimpleXMLElement[] $dataElements */
        $dataElements = $xml->MusicInfo?->Data ?? [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($dataElements as $songData) {
            $musicId = (string) $songData->musicid;

            $genre = SongGenre::tryFromXml((string) $songData->genrename);
            $partsSet = SongPartsSet::tryFrom((string) $songData->partsset);
            if ($genre === null || $partsSet === null) {
                $this->warn(sprintf(
                    "%s: skipped %s (genre '%s', partsset '%s')",
                    $version->value,
                    $musicId,
                    (string) $songData->genrename,
                    (string) $songData->partsset,
                ));
                $skipped++;

                continue;
            }

            // wai2partsset is absent in some serializations; treat empty/unknown as blank.
            $wai2 = SongWai2PartsSet::tryFrom((string) $songData->wai2partsset)?->value ?? '';
            $uniqueId = (int) $songData->uniqueid;

            $attributes = [
                'song_no' => $uniqueId,
                'unique_id' => $uniqueId,
                'title' => (string) $songData->musicname,
                'title_en' => null,
                'genre' => $genre->value,
                'partsset' => $partsSet->value,
                'wai2_partsset' => $wai2,
                'flags' => [
                    'hasextreme' => (int) $songData->hasextreme === 1,
                    'papamama' => (int) $songData->papamama === 1,
                    'secret' => (int) $songData->secret === 1,
                    'newrelease' => (int) $songData->newrelease === 1,
                    'demoplay' => (int) $songData->demoplay === 1,
                ],
                'tags' => $this->tags($songData),
            ];

            $record = Song::query()->where('version', $version->value)->where('music_id', $musicId)->first();
            if ($record === null) {
                Song::query()->create(['version' => $version->value, 'music_id' => $musicId, ...$attributes]);
                $created++;
            } else {
                $record->update($attributes);
                $updated++;
            }
        }

        return [
            'total' => count($dataElements),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function tags(\SimpleXMLElement $songData): array
    {
        $tags = [];
        foreach ($songData->tag as $tagValue) {
            $tags[] = (int) $tagValue;
        }

        while (count($tags) < 16) {
            $tags[] = 0;
        }

        return $tags;
    }

    private function musicInfoPath(string $dataPath, TaikoGameVersion $version): string
    {
        $candidates = [
            "{$dataPath}/{$version->value}/musicinfo.xml",
            "{$dataPath}/{$version->updateIdentifier()}/musicinfo.xml",
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}
