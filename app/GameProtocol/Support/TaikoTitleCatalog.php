<?php

namespace App\GameProtocol\Support;

use App\Enums\TaikoGameVersion;
use RuntimeException;

final class TaikoTitleCatalog
{
    /**
     * @var array{
     *     titles: array<int, array{id: int, name: string, plate: int}>,
     *     versions: array<string, array{min: int, max: int, exclude: array<int, int>}>
     * }|null
     */
    private ?array $data = null;

    public function __construct(private readonly ?string $catalogPath = null) {}

    /**
     * @return array<int, array{id: int, name: string, plate: int}>
     */
    public function titles(TaikoGameVersion $version): array
    {
        $range = $this->data()['versions'][$version->value] ?? null;
        if ($range === null) {
            return [];
        }

        $excluded = array_fill_keys($range['exclude'], true);

        return array_values(array_filter(
            $this->data()['titles'],
            fn (array $title): bool => $title['id'] >= $range['min']
                && $title['id'] <= $range['max']
                && ! isset($excluded[$title['id']])
        ));
    }

    /**
     * @return array{id: int, name: string, plate: int}|null
     */
    public function find(TaikoGameVersion $version, int $titleId): ?array
    {
        foreach ($this->titles($version) as $title) {
            if ($title['id'] === $titleId) {
                return $title;
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    public function ids(TaikoGameVersion $version): array
    {
        return array_column($this->titles($version), 'id');
    }

    public function selectedTitleId(
        TaikoGameVersion $version,
        string $titleText,
        int $titlePlateId,
    ): int {
        foreach ($this->titles($version) as $title) {
            if ($title['name'] === $titleText && $title['plate'] === $titlePlateId) {
                return $title['id'];
            }
        }

        return 0;
    }

    /**
     * @return array{
     *     titles: array<int, array{id: int, name: string, plate: int}>,
     *     versions: array<string, array{min: int, max: int, exclude: array<int, int>}>
     * }
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        /** @var array{
         *     titles: array<int, array{id: int, name: string, plate: int}>,
         *     versions: array<string, array{min: int, max: int, exclude: array<int, int>}>
         * } $data
         */
        $contents = file_get_contents($this->catalogPath ?? resource_path('game-data/title-catalog.json'));
        if ($contents === false) {
            throw new RuntimeException('Unable to read the Taiko title catalog.');
        }

        $data = json_decode(
            $contents,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $this->data = $data;
    }
}
