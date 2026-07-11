<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExtraSongRequest;
use App\Models\ExtraChart;
use App\Models\ExtraChartBest;
use App\Models\ExtraSong;
use App\Models\Player;
use App\Services\ExtraRankAggregateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExtraSongController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/ExtraSongs', [
            'songs' => ExtraSong::query()
                ->with('charts:id,extra_song_id,difficulty,sha256')
                ->latest()
                ->paginate(50),
            'pending' => ExtraChart::query()
                ->whereNull('extra_song_id')
                ->withCount('bests')
                ->latest('last_seen_at')
                ->limit(50)
                ->get(['id', 'sha256', 'observed_title', 'observed_source_id', 'last_seen_at']),
        ]);
    }

    public function store(StoreExtraSongRequest $request, ExtraRankAggregateService $aggregates): RedirectResponse
    {
        $validated = $request->validated();
        $uploads = collect($request->file('charts', []))
            ->filter()
            ->mapWithKeys(fn ($file, mixed $difficulty): array => [
                (int) $difficulty => hash_file('sha256', $file->getRealPath()),
            ]);

        if ($uploads->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['charts' => 'Each difficulty must contain a different chart binary.']);
        }

        if (ExtraChart::query()->whereIn('sha256', $uploads->values())->whereNotNull('extra_song_id')->exists()) {
            throw ValidationException::withMessages(['charts' => 'One of these chart hashes is already registered to another Extra song.']);
        }

        $chartIds = DB::transaction(function () use ($validated, $uploads): array {
            $song = ExtraSong::query()->create([
                'title' => $validated['title'],
                'subtitle' => $validated['subtitle'] ?? null,
                'edition' => $validated['edition'] ?? null,
                'is_ranked' => true,
            ]);

            return $uploads->map(function (string $sha256, int $difficulty) use ($song): int {
                $chart = ExtraChart::query()->firstOrNew(['sha256' => $sha256]);
                $chart->fill([
                    'extra_song_id' => $song->id,
                    'difficulty' => $difficulty,
                    'observed_title' => $chart->observed_title ?: $song->title,
                ])->save();

                return (int) $chart->id;
            })->values()->all();
        });

        ExtraChartBest::query()
            ->whereIn('extra_chart_id', $chartIds)
            ->distinct()
            ->pluck('baid')
            ->each(function (mixed $baid) use ($aggregates): void {
                $player = Player::query()->find((int) $baid);
                if ($player instanceof Player) {
                    $aggregates->recompute($player);
                }
            });

        return back()->with('status', 'Extra song registered. Existing matching bests now count in rankings.');
    }
}
