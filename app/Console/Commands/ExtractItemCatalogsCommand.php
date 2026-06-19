<?php

namespace App\Console\Commands;

use App\Enums\TaikoGameVersion;
use App\Services\GameItemCatalogExtractor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

#[Signature('app:extract-item-catalogs
    {version? : Game version (omit to extract every supported version)}
    {--source= : Root containing the PS3 game dump folders}
    {--output= : Output root; defaults to the configured game-data path}')]
#[Description('Extract verified shop item IDs and metadata from Taiko game dumps')]
class ExtractItemCatalogsCommand extends Command
{
    public function __construct(private readonly GameItemCatalogExtractor $extractor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sourceRoot = (string) $this->option('source');
        if ($sourceRoot === '' || ! is_dir($sourceRoot)) {
            $this->error('A valid --source game dump root is required.');

            return self::FAILURE;
        }

        $versionArgument = $this->argument('version');
        if ($versionArgument !== null) {
            $version = TaikoGameVersion::fromInput((string) $versionArgument);
            if (! $version instanceof TaikoGameVersion) {
                $this->error("Unknown game version: {$versionArgument}");

                return self::FAILURE;
            }

            $versions = [$version];
        } else {
            $versions = TaikoGameVersion::cases();
        }

        $outputRoot = (string) (
            $this->option('output')
            ?: Config::get('taiko_green.data_path', storage_path('app/game-data'))
        );
        $failed = false;

        foreach ($versions as $version) {
            $gameFolder = $this->extractor->locateGameFolder($sourceRoot, $version);
            if ($gameFolder === null) {
                $this->warn("{$version->value}: game folder not found");
                $failed = $failed || $versionArgument !== null;

                continue;
            }

            try {
                $catalog = $this->extractor->extract($version, $gameFolder);
                $outputPath = "{$outputRoot}/{$version->value}/{$version->value}_item_catalog.json";
                File::ensureDirectoryExists(dirname($outputPath));
                File::put($outputPath, json_encode(
                    $catalog,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ).PHP_EOL);

                $counts = collect($catalog['item_types'])
                    ->map(fn (array $type, string $name): string => "{$name}={$type['count']}")
                    ->implode(', ');

                $this->info("{$version->value}: {$counts} items -> {$outputPath}");
            } catch (JsonException|RuntimeException $exception) {
                $this->error("{$version->value}: {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
