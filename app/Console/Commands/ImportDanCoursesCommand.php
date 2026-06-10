<?php

namespace App\Console\Commands;

use App\Enums\TaikoGameVersion;
use App\Models\DanCourse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class ImportDanCoursesCommand extends Command
{
    protected $signature = 'app:import-dan-courses {version? : Game version (omit to import every version)} {--source= : Root of a PS3 game/ dump to copy musicmedleyinfo.xml from before importing}';

    protected $description = 'Import dan dojo courses from musicmedleyinfo.xml for one or all Taiko versions';

    /**
     * Course slots the cabinet accepts (challengelv 1-25).
     */
    private const MAX_DAN_SLOT = 25;

    /**
     * Songs per course the cabinet renders.
     */
    private const MAX_SONGS_PER_COURSE = 10;

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

            $count = $this->importVersion($dataPath, $version);
            if ($count === null) {
                $fatal = $fatal || $versionArg !== null;

                continue;
            }

            $this->info("{$version->value}: {$count} dan courses imported");
        }

        return $fatal ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copy this version's musicmedleyinfo.xml out of a dump into the canonical
     * storage location. Each version's authoritative dan data lives in its OWN
     * colour dump (the config/<board> variants in other dumps are a newer
     * cabinet's view of an older board and differ).
     */
    private function syncFromSource(string $source, string $dataPath, TaikoGameVersion $version): bool
    {
        $dataDir = $this->locateDataDir($source, $version);
        if ($dataDir === null) {
            $this->warn("No game data dir for {$version->value} under {$source}");

            return false;
        }

        $sourceFile = $this->locateMedleyFile($dataDir, $version);
        if ($sourceFile === null) {
            $this->warn("No musicmedleyinfo.xml for {$version->value} under {$dataDir}");

            return false;
        }

        $targetDir = "{$dataPath}/{$version->value}";
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            $this->warn("Could not create {$targetDir}");

            return false;
        }

        copy($sourceFile, "{$targetDir}/musicmedleyinfo.xml");

        return true;
    }

    /**
     * Resolve <source>/<colour>/<serial>/USRDIR/data for a version. Dumps nest a
     * serial id folder (e.g. SCEEX001) between the colour folder and USRDIR.
     */
    private function locateDataDir(string $source, TaikoGameVersion $version): ?string
    {
        if (! is_dir($source)) {
            return null;
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $colour = "{$source}/{$entry}";
            if (! is_dir($colour) || stripos($entry, $version->label()) === false) {
                continue;
            }

            // USRDIR may sit directly under the colour folder or under a serial id.
            if (is_dir("{$colour}/USRDIR/data")) {
                return "{$colour}/USRDIR/data";
            }
            foreach (scandir($colour) ?: [] as $serial) {
                if ($serial !== '.' && $serial !== '..' && is_dir("{$colour}/{$serial}/USRDIR/data")) {
                    return "{$colour}/{$serial}/USRDIR/data";
                }
            }
        }

        return null;
    }

    /**
     * Pick the version's own dan datatable: the base file when present, else the
     * config/<board> variant matching this version's board number (the suffix
     * varies, e.g. ST8100-7 for red).
     */
    private function locateMedleyFile(string $dataDir, TaikoGameVersion $version): ?string
    {
        if (is_file("{$dataDir}/musicmedleyinfo.xml")) {
            return "{$dataDir}/musicmedleyinfo.xml";
        }

        $configDir = "{$dataDir}/config";
        if (! is_dir($configDir)) {
            return null;
        }

        // Board number is the NNN before "100" in the update identifier, e.g.
        // ST8100-1 => 8100, ST-11100-1 => 11100.
        preg_match('/(\d+)100/', $version->updateIdentifier(), $matches);
        $board = ($matches[1] ?? '').'100';

        foreach (scandir($configDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $file = "{$configDir}/{$entry}/musicmedleyinfo.xml";
            if (str_contains($entry, $board) && is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    private function importVersion(string $dataPath, TaikoGameVersion $version): ?int
    {
        $xmlPath = "{$dataPath}/{$version->value}/musicmedleyinfo.xml";
        if (! is_file($xmlPath)) {
            $this->warn("No musicmedleyinfo.xml for {$version->value} at {$xmlPath}");

            return null;
        }

        // Some dumps ship a truncated final entry (e.g. yellow). Parse in recover
        // mode so the well-formed courses still import; libxml errors are
        // collected rather than thrown.
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) file_get_contents($xmlPath), 'SimpleXMLElement', LIBXML_RECOVER | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $this->warn("Failed to parse {$xmlPath}");

            return null;
        }

        $courses = $xml->MusicMedleyInfoData ?? [];

        return DB::transaction(function () use ($courses, $version): int {
            // Re-import is authoritative: drop the version's courses (cascades to
            // songs) and rebuild from the datatable.
            DanCourse::query()->where('version', $version->value)->delete();

            $imported = 0;
            foreach ($courses as $course) {
                $dan = (int) $course->challengelv;
                if ($dan < 1 || $dan > self::MAX_DAN_SLOT) {
                    continue;
                }

                $songs = [];
                foreach ($course->Content as $content) {
                    $songNo = (int) $content->uniqueid;
                    if ($songNo <= 0 || count($songs) >= self::MAX_SONGS_PER_COURSE) {
                        continue;
                    }

                    $songs[] = [
                        'song_no' => $songNo,
                        'level' => (int) $content->difficulty,
                        'sort_order' => count($songs),
                    ];
                }

                if ($songs === []) {
                    continue;
                }

                $record = DanCourse::query()->create([
                    'version' => $version->value,
                    'dan' => $dan,
                    'unique_id' => (int) $course->uniqueid,
                    'name' => (string) $course->medleyname,
                    'difficulty' => (int) $course->difficulty,
                    'verup_no' => 1,
                ]);

                $record->songs()->createMany($songs);
                $imported++;
            }

            return $imported;
        });
    }
}
