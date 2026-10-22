<?php

declare(strict_types=1);

namespace Halcones\Infrastructure;

use PDO;

final class OutboxService
{
    public static function append(PDO $db, string $event, string $aggregateType, int $aggregateId, array $payload): void
    {
        $insert = $db->prepare(
            'INSERT INTO outbox_events (id, event_name, aggregate_type, aggregate_id, payload)
             VALUES (:id, :event, :type, :aggregate_id, :payload)'
        );
        $insert->execute([
            'id' => self::uuid(),
            'event' => $event,
            'type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
