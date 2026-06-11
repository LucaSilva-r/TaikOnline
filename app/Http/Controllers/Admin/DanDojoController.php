<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TaikoGameVersion;
use App\Http\Controllers\Controller;
use App\Models\DanCourse;
use App\Models\Song;
use App\Services\DanCourseRandomizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DanDojoController extends Controller
{
    public function __construct(private readonly DanCourseRandomizer $randomizer) {}

    public function index(Request $request): Response
    {
        $isAll = (bool) $request->attributes->get('taikoVersionIsAll', false);
        $scope = (string) $request->attributes->get('taikoVersionScope');

        $courses = DanCourse::query()
            ->with('songs')
            ->when(! $isAll, fn ($query) => $query->where('version', $scope))
            ->orderBy('version')
            ->orderBy('dan')
            ->get()
            ->groupBy('version');

        $songCounts = Song::query()
            ->selectRaw('version, count(*) as total')
            ->when(! $isAll, fn ($query) => $query->where('version', $scope))
            ->groupBy('version')
            ->pluck('total', 'version');

        $versions = collect(TaikoGameVersion::cases())
            ->when(! $isAll, fn ($versions) => $versions->filter(fn (TaikoGameVersion $version): bool => $version->value === $scope))
            ->map(fn (TaikoGameVersion $version): array => [
                'value' => $version->value,
                'label' => $version->label(),
                'song_count' => (int) ($songCounts[$version->value] ?? 0),
                'courses' => $courses->get($version->value, collect())
                    ->map(fn (DanCourse $course): array => [
                        'dan' => $course->dan,
                        'name' => $course->name,
                        'verup_no' => $course->verup_no,
                        'songs' => $course->songs
                            ->map(fn ($song): array => [
                                'song_no' => $song->song_no,
                                'level' => $song->level,
                            ])
                            ->values(),
                    ])
                    ->values(),
            ])
            ->values();

        return Inertia::render('admin/DanDojo', [
            'versions' => $versions,
            'status' => session('status'),
        ]);
    }

    public function randomize(string $version): RedirectResponse
    {
        $game = TaikoGameVersion::fromInput($version);
        if (! $game instanceof TaikoGameVersion) {
            abort(404);
        }

        $created = $this->randomizer->randomize($game);

        return back()->with('status', $created > 0
            ? "Randomized {$created} dan courses for {$game->label()}."
            : "No songs imported for {$game->label()}; nothing to randomize.");
    }
}
