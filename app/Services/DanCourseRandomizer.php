<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use App\Models\DanCourse;
use App\Models\Song;
use Illuminate\Support\Facades\DB;

/**
 * Generates server-authored dan dojo courses from a version's own song catalog.
 *
 * This is the "daily challenge" path: it proves a dan dojo can be defined for any
 * version without an arcade datatable, since the cabinet only needs the song list
 * (it evaluates pass/fail from its own bundled conditions per dan slot).
 */
class DanCourseRandomizer
{
    private const COURSES = 10;

    private const SONGS_PER_COURSE = 3;

    /**
     * Replace a version's dan courses with a fresh random set drawn from its song
     * catalog. Returns the number of courses created (0 when the version has no
     * songs imported).
     */
    public function randomize(TaikoGameVersion $version): int
    {
        $songNumbers = Song::query()
            ->where('version', $version->value)
            ->pluck('song_no')
            ->map(fn (mixed $songNo): int => (int) $songNo)
            ->filter(fn (int $songNo): bool => $songNo > 0)
            ->values();

        if ($songNumbers->count() < self::SONGS_PER_COURSE) {
            return 0;
        }

        return DB::transaction(function () use ($version, $songNumbers): int {
            DanCourse::query()->where('version', $version->value)->delete();

            // Bump verup so the cabinet treats the dojo as changed and re-reads it.
            // Unix time is monotonic and fits the wire field's uint32.
            $verup = time();

            for ($dan = 1; $dan <= self::COURSES; $dan++) {
                $picks = $songNumbers->shuffle()->take(self::SONGS_PER_COURSE)->values();
                $level = $this->levelForDan($dan);

                $course = DanCourse::query()->create([
                    'version' => $version->value,
                    'dan' => $dan,
                    'unique_id' => 90000 + $dan,
                    'name' => "Daily Challenge {$dan}",
                    'difficulty' => $level,
                    'verup_no' => $verup,
                ]);

                $course->songs()->createMany(
                    $picks->map(fn (int $songNo, int $index): array => [
                        'song_no' => $songNo,
                        'level' => $level,
                        'sort_order' => $index,
                    ])->all(),
                );
            }

            return self::COURSES;
        });
    }

    /**
     * Chart difficulty for a dan slot. The cabinet's dan datatable only ever uses
     * 0–3 (easy, normal, hard, oni); 4 (ura) is invalid here and crashes the
     * dojo. Scale up with the dan: normal → hard → oni.
     */
    private function levelForDan(int $dan): int
    {
        return (int) min(3, intdiv($dan - 1, 3) + 1);
    }
}
