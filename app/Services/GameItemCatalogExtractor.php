<?php

namespace App\Services;

use App\Enums\TaikoGameVersion;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SimpleXMLElement;

class GameItemCatalogExtractor
{
    /**
     * @return array{
     *     schema_version: int,
     *     game_version: string,
     *     source_game_folder: string,
     *     item_types: array<string, array{
     *         item_type: int,
     *         count: int,
     *         items: array<int, array<string, mixed>>
     *     }>
     * }
     */
    public function extract(TaikoGameVersion $version, string $gameFolder): array
    {
        $gameFolder = rtrim($gameFolder, DIRECTORY_SEPARATOR);
        $dataPath = $gameFolder.DIRECTORY_SEPARATOR.'USRDIR'.DIRECTORY_SEPARATOR.'data';

        if (! is_dir($dataPath)) {
            throw new RuntimeException("Game data directory does not exist: {$dataPath}");
        }

        $nameTextures = [
            'tone' => $this->nameTextureCatalog($dataPath, $version, 'tone_name'),
            'kigurumi' => $this->nameTextureCatalog($dataPath, $version, 'costume_name'),
            'body' => $this->nameTextureCatalog($dataPath, $version, 'costume_body_name'),
            'head' => $this->nameTextureCatalog($dataPath, $version, 'costume_head_name'),
            'puchi' => $this->puchiNameTextureCatalog($dataPath, $version),
        ];

        $itemTypes = [
            'song' => $this->itemType(1, $this->songs($dataPath, $gameFolder)),
            'tone' => $this->itemType(2, $this->textureOnlyItems($nameTextures['tone'])),
            'kigurumi' => $this->itemType(3, $this->assetItems(
                $dataPath,
                $gameFolder,
                $nameTextures['kigurumi'],
                $this->fullCostumeDirectories($dataPath),
                '/^cos_?(\d{3})\d{3}.*\.(?:nud|nut)$/i',
            )),
            'body' => $this->itemType(4, $this->assetItems(
                $dataPath,
                $gameFolder,
                $nameTextures['body'],
                ["{$dataPath}/don3d/parts/body"],
                '/^body_?(\d{3})\d{3}.*\.(?:nud|nut)$/i',
            )),
            'head' => $this->itemType(5, $this->assetItems(
                $dataPath,
                $gameFolder,
                $nameTextures['head'],
                ["{$dataPath}/don3d/parts/head"],
                '/^head_?(\d{3})\d{3}.*\.(?:nud|nut)$/i',
            )),
            'face' => $this->itemType(6, $this->assetItems(
                $dataPath,
                $gameFolder,
                [],
                ["{$dataPath}/don3d/parts/paint"],
                '/^paint_?(\d{3})\d{3}.*\.nut$/i',
            )),
            'puchi' => $this->itemType(7, $this->assetItems(
                $dataPath,
                $gameFolder,
                $nameTextures['puchi'],
                ["{$dataPath}/don3d/parts/acc"],
                '/^acc_?(\d{3})\d{3}.*\.(?:nud|nut)$/i',
            )),
        ];

        return [
            'schema_version' => 1,
            'game_version' => $version->value,
            'source_game_folder' => basename($gameFolder),
            'item_types' => $itemTypes,
        ];
    }

    public function locateGameFolder(string $sourceRoot, TaikoGameVersion $version): ?string
    {
        if (! is_dir($sourceRoot)) {
            return null;
        }

        foreach (File::directories($sourceRoot) as $directory) {
            if (
                stripos(basename($directory), $version->label()) !== false
                && is_dir($directory.DIRECTORY_SEPARATOR.'USRDIR'.DIRECTORY_SEPARATOR.'data')
            ) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{item_type: int, count: int, items: array<int, array<string, mixed>>}
     */
    private function itemType(int $itemType, array $items): array
    {
        return [
            'item_type' => $itemType,
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function songs(string $dataPath, string $gameFolder): array
    {
        $musicInfoPath = $this->bestMusicInfo($dataPath);
        if ($musicInfoPath === null) {
            return [];
        }

        $xml = simplexml_load_file($musicInfoPath);
        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $items = [];
        foreach ($xml->MusicInfo?->Data ?? [] as $song) {
            $itemId = (int) $song->uniqueid;
            if ($itemId === 0) {
                continue;
            }

            $items[$itemId] = [
                'item_id' => $itemId,
                'name' => (string) $song->musicname,
                'music_id' => (string) $song->musicid,
                'genre' => (string) $song->genrename,
                'source' => $this->relativePath($musicInfoPath, $gameFolder),
            ];
        }

        ksort($items, SORT_NUMERIC);

        return array_values($items);
    }

    private function bestMusicInfo(string $dataPath): ?string
    {
        $candidates = [];
        if (is_file("{$dataPath}/musicinfo.xml")) {
            $candidates[] = "{$dataPath}/musicinfo.xml";
        }

        foreach (glob("{$dataPath}/config/*/musicinfo.xml") ?: [] as $candidate) {
            $candidates[] = $candidate;
        }

        $bestPath = null;
        $bestCount = -1;

        foreach ($candidates as $candidate) {
            $xml = @simplexml_load_file($candidate);
            $count = $xml instanceof SimpleXMLElement
                ? count($xml->MusicInfo?->Data ?? [])
                : 0;

            if ($count > $bestCount) {
                $bestPath = $candidate;
                $bestCount = $count;
            }
        }

        return $bestPath;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nameTextures
     * @param  array<int, string>  $directories
     * @return array<int, array<string, mixed>>
     */
    private function assetItems(
        string $dataPath,
        string $gameFolder,
        array $nameTextures,
        array $directories,
        string $pattern,
    ): array {
        $assetsById = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (File::files($directory) as $file) {
                if (preg_match($pattern, $file->getFilename(), $matches) !== 1) {
                    continue;
                }

                $itemId = (int) $matches[1];
                if ($itemId === 0) {
                    continue;
                }

                $assetsById[$itemId][] = $this->relativePath($file->getPathname(), $gameFolder);
            }
        }

        ksort($assetsById, SORT_NUMERIC);
        $items = [];

        foreach ($assetsById as $itemId => $assetPaths) {
            sort($assetPaths);
            $item = [
                'item_id' => $itemId,
                'name' => null,
                'assets' => array_values(array_unique($assetPaths)),
            ];

            if (isset($nameTextures[$itemId])) {
                $item['name_texture'] = $nameTextures[$itemId];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nameTextures
     * @return array<int, array<string, mixed>>
     */
    private function textureOnlyItems(array $nameTextures): array
    {
        $items = [];

        foreach ($nameTextures as $itemId => $texture) {
            if ($itemId === 0) {
                continue;
            }

            $items[] = [
                'item_id' => $itemId,
                'name' => null,
                'name_texture' => $texture,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function fullCostumeDirectories(string $dataPath): array
    {
        $fullDirectory = "{$dataPath}/don3d/full/cos";

        return is_dir($fullDirectory)
            ? [$fullDirectory]
            : ["{$dataPath}/don3d/cos"];
    }

    /**
     * @return array<int, array{
     *     source: string,
     *     texture_index: int,
     *     texture_gidx: int,
     *     width: int,
     *     height: int
     * }>
     */
    private function nameTextureCatalog(
        string $dataPath,
        TaikoGameVersion $version,
        string $catalogName,
    ): array {
        $boardIdentifier = $this->assetBoardIdentifier($version);
        $pattern = "{$dataPath}/nutdata/{$boardIdentifier}/appendable/*/{$catalogName}/{$catalogName}.nut";
        $paths = glob($pattern) ?: [];

        usort($paths, function (string $left, string $right): int {
            return $this->appendableNumber($left) <=> $this->appendableNumber($right);
        });

        $catalog = [];
        $itemId = 0;

        foreach ($paths as $path) {
            foreach ($this->ntp3Textures($path) as $texture) {
                $catalog[$itemId++] = [
                    'source' => $this->relativePath($path, dirname($dataPath, 2)),
                    ...$texture,
                ];
            }
        }

        return $catalog;
    }

    /**
     * @return array<int, array{
     *     source: string,
     *     texture_index: int,
     *     texture_gidx: int,
     *     width: int,
     *     height: int
     * }>
     */
    private function puchiNameTextureCatalog(string $dataPath, TaikoGameVersion $version): array
    {
        $path = sprintf(
            '%s/nutdata/%s/rewardgasha/acc_name_000.nut',
            $dataPath,
            $this->assetBoardIdentifier($version),
        );

        if (! is_file($path)) {
            return [];
        }

        $catalog = [];
        foreach ($this->ntp3Textures($path) as $texture) {
            $catalog[$texture['texture_gidx']] = [
                'source' => $this->relativePath($path, dirname($dataPath, 2)),
                ...$texture,
            ];
        }

        return $catalog;
    }

    private function assetBoardIdentifier(TaikoGameVersion $version): string
    {
        return match ($version) {
            TaikoGameVersion::Blue => 'S10100-1',
            TaikoGameVersion::Green => 'S11100-1',
            default => str_replace('ST-', 'ST', $version->updateIdentifier()),
        };
    }

    private function appendableNumber(string $path): int
    {
        return preg_match('~/appendable/(\d+)/~', $path, $matches) === 1
            ? (int) $matches[1]
            : PHP_INT_MAX;
    }

    /**
     * @return array<int, array{
     *     texture_index: int,
     *     texture_gidx: int,
     *     width: int,
     *     height: int
     * }>
     */
    private function ntp3Textures(string $path): array
    {
        $data = file_get_contents($path);
        if ($data === false || substr($data, 0, 4) !== 'NTP3' || strlen($data) < 16) {
            return [];
        }

        $textureCount = $this->uint16($data, 6);
        $offset = 16;
        $textures = [];

        for ($index = 0; $index < $textureCount; $index++) {
            if ($offset + 76 > strlen($data)) {
                break;
            }

            $entrySize = $this->uint32($data, $offset);
            if ($entrySize < 76 || $offset + $entrySize > strlen($data)) {
                break;
            }

            $textures[] = [
                'texture_index' => $index,
                'texture_gidx' => $this->uint32($data, $offset + 72),
                'width' => $this->uint16($data, $offset + 20),
                'height' => $this->uint16($data, $offset + 22),
            ];

            $offset += $entrySize;
        }

        return $textures;
    }

    private function uint16(string $data, int $offset): int
    {
        return (int) (unpack('nvalue', substr($data, $offset, 2))['value'] ?? 0);
    }

    private function uint32(string $data, int $offset): int
    {
        return (int) (unpack('Nvalue', substr($data, $offset, 4))['value'] ?? 0);
    }

    private function relativePath(string $path, string $gameFolder): string
    {
        $prefix = rtrim($gameFolder, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix)
            ? substr($path, strlen($prefix))
            : $path;
    }
}
