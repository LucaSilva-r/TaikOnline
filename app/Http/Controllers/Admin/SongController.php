<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Song;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SongController extends Controller
{
    public function index(Request $request): Response
    {
        $isAll = (bool) $request->attributes->get('taikoVersionIsAll', false);
        $scope = (string) $request->attributes->get('taikoVersionScope');

        return Inertia::render('admin/Songs', [
            'songs' => Song::query()
                ->when(! $isAll, fn ($query) => $query->where('version', $scope))
                ->when($isAll, fn ($query) => $query->orderBy('version'))
                ->orderBy('song_no')
                ->paginate(50)
                ->through(fn (Song $song): array => [
                    'id' => $song->id,
                    'version' => $song->version,
                    'song_no' => $song->song_no,
                    'music_id' => $song->music_id,
                    'title' => $song->title,
                    'title_en' => $song->title_en,
                    'genre' => [
                        'value' => $song->genre->value,
                        'label' => $song->genre->label(),
                        'label_jp' => $song->genre->labelJp(),
                    ],
                    'partsset' => [
                        'value' => $song->partsset->value,
                        'label' => $song->partsset->label(),
                    ],
                    'has_extreme' => $song->flags['hasextreme'] ?? false,
                    'has_papamama' => $song->flags['papamama'] ?? false,
                ]),
        ]);
    }
}
