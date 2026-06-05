<?php

namespace App\GameProtocol\Green\Support;

use Google\Protobuf\Internal\Message;

/**
 * Tolerant setter for protobuf messages whose schema drifts between game
 * versions. Calling a setter that does not exist on a given version's message
 * is a no-op rather than a fatal error, so one controller can serve every
 * version without per-field version checks.
 */
class MessageWriter
{
    /**
     * @param  array<string, mixed>  $fields  Map of setter name => value.
     */
    public function fill(Message $message, array $fields): Message
    {
        foreach ($fields as $setter => $value) {
            $this->set($message, $setter, $value);
        }

        return $message;
    }

    public function set(Message $message, string $setter, mixed $value): Message
    {
        if (method_exists($message, $setter)) {
            $message->{$setter}($value);
        }

        return $message;
    }
}
