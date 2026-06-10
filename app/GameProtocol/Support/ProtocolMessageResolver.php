<?php

namespace App\GameProtocol\Support;

use App\Enums\TaikoGameVersion;
use Google\Protobuf\Internal\Message;
use RuntimeException;

/**
 * Resolves the concrete generated protobuf message class for a given game
 * version. Every version compiles into its own namespace
 * (App\GameProtocol\Proto\<Segment>\{Taiko,VsInterface}), so the class is
 * built by interpolation rather than a per-version switch.
 */
class ProtocolMessageResolver
{
    private const NAMESPACE_ROOT = 'App\\GameProtocol\\Proto';

    /** @var array<string, class-string<Message>> */
    private array $cache = [];

    /**
     * @param  string  $name  Message name, possibly nested
     *                        (e.g. "GetfolderResponse\\EventfolderData").
     * @return class-string<Message>
     */
    public function class(TaikoGameVersion $version, string $name, string $group = 'Taiko'): string
    {
        $cacheKey = "{$version->value}|{$group}|{$name}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $base = self::NAMESPACE_ROOT.'\\'.$version->namespaceSegment().'\\'.$group;

        // Message names drift in casing between versions (Telopcheck vs TelopCheck,
        // Gettelop vs GetTelop), so fall back to a case-insensitive lookup.
        $class = "{$base}\\{$name}";
        if (! class_exists($class)) {
            $resolved = $this->resolveCaseInsensitive($base, $name);
            $class = $resolved !== null && class_exists($resolved) ? $resolved : $class;
        }

        if (! class_exists($class)) {
            throw new RuntimeException(
                "Protobuf message [{$name}] is not defined for version [{$version->value}] (looked for {$base}\\{$name})."
            );
        }

        /** @var class-string<Message> $class */
        return $this->cache[$cacheKey] = $class;
    }

    /**
     * Resolve a (possibly nested) message name against the on-disk class files
     * for a version, matching each namespace segment case-insensitively.
     */
    private function resolveCaseInsensitive(string $base, string $name): ?string
    {
        // App\GameProtocol\... maps to app/GameProtocol/...
        $dir = app_path(str_replace('\\', '/', substr($base, strlen('App\\'))));
        $resolved = $base;

        foreach (explode('\\', $name) as $segment) {
            if (! is_dir($dir)) {
                return null;
            }

            $match = null;
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $candidate = str_ends_with($entry, '.php') ? substr($entry, 0, -4) : $entry;
                if (strcasecmp($candidate, $segment) === 0) {
                    $match = $candidate;
                    break;
                }
            }

            if ($match === null) {
                return null;
            }

            $resolved .= '\\'.$match;
            $dir .= '/'.$match;
        }

        return $resolved;
    }

    /**
     * @template TMessage of Message
     */
    public function make(TaikoGameVersion $version, string $name, string $group = 'Taiko'): Message
    {
        $class = $this->class($version, $name, $group);

        return new $class;
    }

    /**
     * Like make(), but returns null when the message is not defined for this
     * version (e.g. a nested message that only newer versions carry).
     */
    public function tryMake(TaikoGameVersion $version, string $name, string $group = 'Taiko'): ?Message
    {
        try {
            return $this->make($version, $name, $group);
        } catch (\Throwable) {
            return null;
        }
    }
}
