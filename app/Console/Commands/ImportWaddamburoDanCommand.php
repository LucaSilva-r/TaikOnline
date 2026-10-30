<?php

namespace App\Console\Commands;

use App\Models\WdbDanCourse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use SimpleXMLElement;

/**
 * Loads the dan courses in database/data/dan/<series>.json (the facts of each course, committed; the game
 * files they come from are not) into the database. Given a series and a musicmedleyinfo.xml, it first
 * rewrites that series' file from the XML. The series is named by hand: an install's own file usually holds
 * the list of the version before it, so a folder name proves nothing. A malformed XML is refused (Yellow's own
 * copy is cut short; the complete list is in an upgraded Green's config/ST9100-1).
 */
#[Signature('app:import-waddamburo-dan
    {series? : The series a musicmedleyinfo.xml holds, to rewrite its data file}
    {file? : That musicmedleyinfo.xml}
    {--data= : The data folder (default database/data/dan)}')]
#[Description('Load the Waddamburo dan dojo courses, optionally rewriting one series from a game file')]
class ImportWaddamburoDanCommand extends Command
{
    /** Requirement type => its XML element, in the order the game lists them. */
    private const REQUIREMENTS = [
        'soul_gauge' => 'tamashii',
        'goods' => 'hit_ryo',
        'oks' => 'hit_ka',
        'bads' => 'hit_fuka',
        'combo' => 'combo',
        'hits' => 'hits',
        'score' => 'score',
        'rolls' => 'renda',
    ];

    public function handle(): int
    {
        $folder = (string) ($this->option('data') ?? database_path('data/dan'));
        $series = $this->argument('series');
        $file = $this->argument('file');
        if (($series === null) !== ($file === null)) {
            $this->error('Give both a series and its file, or neither.');

            return self::FAILURE;
        }
        if ($series !== null) {
            $courses = $this->fromXml((string) $series, (string) $file);
            if ($courses === null) {
                return self::FAILURE;
            }
            if (! is_dir($folder)) {
                mkdir($folder, 0775, true);
            }
            file_put_contents("{$folder}/{$series}.json", json_encode($courses,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }

        foreach (array_keys(WdbDanCourse::SERIES) as $id) {
            $path = "{$folder}/{$id}.json";
            if (is_file($path)) {
                try {
                    $count = $this->load($id, json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
                } catch (JsonException $exception) {
                    $this->error("{$path}: {$exception->getMessage()}");

                    return self::FAILURE;
                }
                $this->info("{$id}: {$count} courses loaded.");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $courses
     */
    private function load(string $series, array $courses): int
    {
        DB::transaction(function () use ($series, $courses): void {
            WdbDanCourse::query()->where('series', $series)->whereNotIn('level', array_column($courses, 'level'))->delete();
            foreach ($courses as $course) {
                $record = WdbDanCourse::query()->updateOrCreate(['series' => $series, 'level' => $course['level']], [
                    'name' => $course['name'],
                    'requirements' => $course['requirements'],
                    'content_hash' => hash('sha256', (string) json_encode([
                        array_map(fn (array $song): array => [$song['song_key'], $song['course']], $course['songs']),
                        $course['requirements'],
                    ])),
                ]);
                $record->songs()->delete();
                $record->songs()->createMany(array_map(
                    fn (array $song, int $position): array => ['position' => $position, ...$song],
                    $course['songs'], array_keys($course['songs'])));
            }
        });

        return count($courses);
    }

    /** @return list<array<string, mixed>>|null null: refused, with the reason printed */
    private function fromXml(string $series, string $path): ?array
    {
        if (! isset(WdbDanCourse::SERIES[$series])) {
            $this->error("Unknown series {$series}: ".implode(', ', array_keys(WdbDanCourse::SERIES)));

            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = is_readable($path) ? simplexml_load_string((string) file_get_contents($path)) : false;
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            $this->error("Cannot read {$path}: missing or malformed.");

            return null;
        }

        $courses = [];
        foreach ($xml->MusicMedleyInfoData as $course) {
            $songs = [];
            foreach ($course->Content as $content) {
                $songs[] = [
                    'song_key' => (string) $content->musicid,
                    'course' => (int) $content->difficulty,
                    'notes' => (int) $content->notes,
                ];
            }
            $level = (int) $course->challengelv;
            if (count($songs) !== 3 || isset($courses[$level])) {
                $this->error("Course {$level} of {$path} is a duplicate or does not have 3 songs.");

                return null;
            }
            $courses[$level] = [
                'level' => $level,
                'name' => (string) $course->medleyname,
                'requirements' => $this->requirements($course, $series),
                'songs' => $songs,
            ];
        }
        if ($courses === []) {
            $this->error("{$path} has no courses.");

            return null;
        }
        ksort($courses);

        return array_values($courses);
    }

    /** @return list<array{type: string, red: list<int>, gold: list<int>}> */
    private function requirements(SimpleXMLElement $course, string $series): array
    {
        $requirements = [];
        foreach (self::REQUIREMENTS as $type => $element) {
            $red = (int) $course->Conditions->{$element};
            $gold = (int) $course->ExcellentConditions->{$element};
            if ($red === 0 && $gold === 0) {
                continue;
            }
            // The file keeps the gauge in hundredths of a percent; players and Nijiiro's data use percent.
            $percent = $type === 'soul_gauge' ? 100 : 1;
            // KATSU-DON counts "X or less"; every later list means "under X". Its gold 0 Bads is a real limit.
            $orLess = $series === 'katsudon' && in_array($type, ['oks', 'bads'], true) ? 1 : 0;
            $requirements[] = ['type' => $type, 'red' => [intdiv($red, $percent) + $orLess], 'gold' => [intdiv($gold, $percent) + $orLess]];
        }

        return $requirements;
    }
}
