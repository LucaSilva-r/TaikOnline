<?php

namespace App\GameProtocol\Handlers;

use App\Enums\TaikoGameVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Blue (v10) dialect handler. Blue's InitialdatacheckResponse diverges from the
 * green-shaped schema, so it is hand-serialised here while every other endpoint
 * inherits the default behaviour.
 */
class BlueGameHandler extends GameHandler
{
    public function initialDataCheck(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'InitialdatacheckRequest');

        return $this->blueInitialDataCheckResponse($this->releaseSongFlag($game->value));
    }

    private function blueInitialDataCheckResponse(string $releaseSongFlag): Response
    {
        $body = $this->protobufVarintField(1, 1)
            .$this->protobufVarintField(2, 1)
            .$this->protobufBytesField(3, $releaseSongFlag)
            .$this->protobufBytesField(4, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(5, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(6, $this->protobufMessage([
                $this->protobufVarintField(1, 1),
                $this->protobufVarintField(2, 2),
            ]))
            .$this->protobufVarintField(10, 1)
            .$this->protobufVarintField(11, 0)
            .$this->protobufVarintField(12, 0)
            .$this->protobufVarintField(14, 1)
            .$this->protobufBytesField(15, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(16, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufVarintField(17, 0);

        return response($body, 200, ['Content-Type' => 'application/protobuf']);
    }

    private function protobufVarintField(int $field, int $value): string
    {
        return $this->protobufVarint(($field << 3) | 0).$this->protobufVarint($value);
    }

    private function protobufBytesField(int $field, string $value): string
    {
        return $this->protobufVarint(($field << 3) | 2).$this->protobufVarint(strlen($value)).$value;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function protobufMessage(array $fields): string
    {
        return implode('', $fields);
    }

    private function protobufVarint(int $value): string
    {
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;

            if ($value !== 0) {
                $byte |= 0x80;
            }

            $bytes .= chr($byte);
        } while ($value !== 0);

        return $bytes;
    }
}
