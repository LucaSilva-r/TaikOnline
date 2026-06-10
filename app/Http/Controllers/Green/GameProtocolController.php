<?php

namespace App\Http\Controllers\Green;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Handlers\GameHandlerRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Thin HTTP entrypoint for the in-game protocol. Resolves the cabinet's dialect
 * from the route segment and dispatches to the matching {@see GameHandler}; all
 * response-building logic lives in the handler layer.
 */
class GameProtocolController extends Controller
{
    /**
     * Route version used when a request arrives without a version segment
     * (e.g. the bare "/" setup probe). Resolves to the green dialect.
     */
    private const DEFAULT_ROUTE_VERSION = 'v11r00';

    public function __construct(private readonly GameHandlerRegistry $handlers) {}

    public function heartbeat(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'heartbeat', $request);
    }

    public function initialDataCheck(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'initialDataCheck', $request);
    }

    public function bookKeeping(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'bookKeeping', $request);
    }

    public function baid(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'baid', $request);
    }

    public function mydonEntry(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'mydonEntry', $request);
    }

    public function userData(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'userData', $request);
    }

    public function playResult(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'playResult', $request);
    }

    public function selfBest(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'selfBest', $request);
    }

    public function crownsData(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'crownsData', $request);
    }

    public function getFolder(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'getFolder', $request);
    }

    public function getTelop(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'getTelop', $request);
    }

    public function songHash(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'songHash', $request);
    }

    public function defaultSong(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'defaultSong', $request);
    }

    public function folderCheck(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'folderCheck', $request);
    }

    public function telopCheck(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'telopCheck', $request);
    }

    public function taikojuku(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'taikojuku', $request);
    }

    public function getGhostData(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'getGhostData', $request);
    }

    public function getGhostScore(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'getGhostScore', $request);
    }

    public function recommend(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'recommend', $request);
    }

    public function tournamentCheck(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'tournamentCheck', $request);
    }

    public function challengeCompe(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'challengeCompe', $request);
    }

    public function rewardCardCheck(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'rewardCardCheck', $request);
    }

    public function rewardExecution(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'rewardExecution', $request);
    }

    public function headClerk2(Request $request, string $version): Response
    {
        return $this->dispatch($version, 'headClerk2', $request);
    }

    public function rootSetup(Request $request): Response
    {
        $payload = $request->getContent();

        if ($this->hasProtobufField($payload, 3, 2)) {
            return $this->dispatch(self::DEFAULT_ROUTE_VERSION, 'bookKeeping', $request);
        }

        if ($this->hasProtobufField($payload, 3, 0)) {
            return $this->dispatch(self::DEFAULT_ROUTE_VERSION, 'getTelop', $request);
        }

        return $this->dispatch('v01r00_tw', 'initialDataCheck', $request);
    }

    /**
     * Resolve the dialect from the route segment and hand the request to the
     * matching handler method.
     */
    private function dispatch(string $routeVersion, string $method, Request $request): Response
    {
        $game = TaikoGameVersion::fromRouteVersion($routeVersion) ?? TaikoGameVersion::Green;

        return $this->handlers->for($game)->{$method}($request, $game);
    }

    private function hasProtobufField(string $payload, int $fieldNumber, int $wireType): bool
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $key = $this->readProtobufVarint($payload, $offset);
            if ($key === null) {
                return false;
            }

            $field = $key >> 3;
            $wire = $key & 0x07;

            if ($field === $fieldNumber && $wire === $wireType) {
                return true;
            }

            if (! $this->skipProtobufValue($payload, $offset, $wire)) {
                return false;
            }
        }

        return false;
    }

    private function skipProtobufValue(string $payload, int &$offset, int $wireType): bool
    {
        return match ($wireType) {
            0 => $this->readProtobufVarint($payload, $offset) !== null,
            1 => $this->skipBytes($payload, $offset, 8),
            2 => $this->skipLengthDelimited($payload, $offset),
            5 => $this->skipBytes($payload, $offset, 4),
            default => false,
        };
    }

    private function skipLengthDelimited(string $payload, int &$offset): bool
    {
        $length = $this->readProtobufVarint($payload, $offset);
        if ($length === null) {
            return false;
        }

        return $this->skipBytes($payload, $offset, $length);
    }

    private function skipBytes(string $payload, int &$offset, int $bytes): bool
    {
        if ($bytes < 0 || $offset + $bytes > strlen($payload)) {
            return false;
        }

        $offset += $bytes;

        return true;
    }

    private function readProtobufVarint(string $payload, int &$offset): ?int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($payload);

        while ($offset < $length && $shift < 64) {
            $byte = ord($payload[$offset]);
            $offset++;
            $result |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $result;
            }

            $shift += 7;
        }

        return null;
    }
}
