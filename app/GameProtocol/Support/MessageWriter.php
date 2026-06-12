<?php

namespace App\GameProtocol\Support;

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
        if ($value === null || ! method_exists($message, $setter)) {
            return $message;
        }

        try {
            $message->{$setter}($value);
        } catch (\TypeError) {
            // Field type drifts between versions (e.g. `title` is a string in
            // green but a uint32 id in sorairo); try to coerce or skip rather than fail.
            try {
                if (is_numeric($value)) {
                    $message->{$setter}((int) $value);
                } else {
                    $message->{$setter}((string) $value);
                }
            } catch (\TypeError) {
            }
        }

        return $message;
    }
}
