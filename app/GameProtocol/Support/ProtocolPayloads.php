<?php

namespace App\GameProtocol\Support;

use Google\Protobuf\Internal\Message;
use Illuminate\Http\Response;

class ProtocolPayloads
{
    /**
     * @template TMessage of Message
     *
     * @param  class-string<TMessage>  $messageClass
     * @return TMessage
     */
    public function parse(string $payload, string $messageClass): Message
    {
        $message = new $messageClass;
        $message->mergeFromString($payload);

        return $message;
    }

    public function response(Message $message): Response
    {
        return response($message->serializeToString(), 200, [
            'Content-Type' => 'application/protobuf',
        ]);
    }

    public function inflatePlayResultData(string $payload): string
    {
        $decoded = $this->decodeGzip($payload);
        if ($decoded !== null) {
            return $decoded;
        }

        if (strlen($payload) > 32) {
            $decoded = $this->decodeGzip(substr($payload, 32));
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return $payload;
    }

    public function deflatePlayResultData(string $payload): string
    {
        return gzencode($payload);
    }

    private function decodeGzip(string $payload): ?string
    {
        $decoded = @gzdecode($payload);
        if ($decoded !== false) {
            return $decoded;
        }

        return null;
    }
}
