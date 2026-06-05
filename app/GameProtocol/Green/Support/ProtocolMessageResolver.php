<?php

namespace App\GameProtocol\Green\Support;

use App\Enums\TaikoGameVersion;
use Google\Protobuf\Internal\Message;
use RuntimeException;

/**
 * Resolves the concrete generated protobuf message class for a given game
 * version. Every version compiles into its own namespace
 * (App\GameProtocol\Green\Proto\<Segment>\{Taiko,VsInterface}), so the class is
 * built by interpolation rather than a per-version switch.
 */
class ProtocolMessageResolver
{
    private const NAMESPACE_ROOT = 'App\\GameProtocol\\Green\\Proto';

    /**
     * @param  string  $name  Message name, possibly nested
     *                        (e.g. "GetfolderResponse\\EventfolderData").
     * @return class-string<Message>
     */
    public function class(TaikoGameVersion $version, string $name, string $group = 'Taiko'): string
    {
        $class = sprintf(
            '%s\\%s\\%s\\%s',
            self::NAMESPACE_ROOT,
            $version->namespaceSegment(),
            $group,
            $name,
        );

        if (! class_exists($class)) {
            throw new RuntimeException(
                "Protobuf message [{$name}] is not defined for version [{$version->value}] (looked for {$class})."
            );
        }

        /** @var class-string<Message> $class */
        return $class;
    }

    /**
     * @template TMessage of Message
     */
    public function make(TaikoGameVersion $version, string $name, string $group = 'Taiko'): Message
    {
        $class = $this->class($version, $name, $group);

        return new $class;
    }
}
