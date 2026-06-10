<?php

namespace App\GameProtocol\Handlers;

use App\Enums\TaikoGameVersion;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the request handler for a Taiko dialect. Versions whose protocol
 * shape matches the default fall through to {@see GameHandler}; only versions
 * that genuinely diverge get a dedicated subclass here.
 */
class GameHandlerRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(TaikoGameVersion $version): GameHandler
    {
        return $this->container->make(match ($version) {
            TaikoGameVersion::Blue => BlueGameHandler::class,
            default => GameHandler::class,
        });
    }
}
