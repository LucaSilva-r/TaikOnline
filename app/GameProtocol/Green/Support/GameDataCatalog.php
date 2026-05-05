<?php

namespace App\GameProtocol\Green\Support;

use Illuminate\Support\Facades\File;

class GameDataCatalog
{
    /**
     * @return array<int, array{name: string, present: bool, path: string}>
     */
    public function status(): array
    {
        $path = (string) config('taiko_green.data_path');
        $required = [
            'musicinfo.bin',
            'music_order.bin',
            'wordlist.bin',
            'don_cos_reward.bin',
            'shougou.bin',
            'neiro.bin',
        ];

        return collect($required)
            ->map(fn (string $name): array => [
                'name' => $name,
                'present' => File::exists($path.DIRECTORY_SEPARATOR.$name),
                'path' => $path.DIRECTORY_SEPARATOR.$name,
            ])
            ->values()
            ->all();
    }
}
