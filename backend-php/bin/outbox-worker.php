<?php

declare(strict_types=1);

use Halcones\Infrastructure\Database;
use Halcones\Infrastructure\PusherFactory;

require dirname(__DIR__) . '/config/bootstrap.php';

$db = Database::connection();
try {
    $pusher = PusherFactory::create();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

$watch = in_array('--watch', $argv ?? [], true);
do {
    $processed = 0;
while ($processed < 100) {
    $db->beginTransaction();
    try {
        $query = $db->query('SELECT id, event_name, aggregate_type, aggregate_id, payload, attempts
            FROM outbox_events
            WHERE published_at IS NULL AND available_at <= UTC_TIMESTAMP(3)
            ORDER BY created_at ASC LIMIT 1 FOR UPDATE');
        $event = $query->fetch();
        if (!$event) {
            $db->commit();
            break;
        }
        $claim = $db->prepare('UPDATE outbox_events SET available_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 30 SECOND) WHERE id = :id');
        $claim->execute(['id' => $event['id']]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    $payload = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
    $channels = ['private-operators'];
    if (isset($payload['driver_user_id'])) {
        $channels[] = 'private-driver.' . (int) $payload['driver_user_id'];
    }
    if ($event['aggregate_type'] === 'service' && isset($payload['public_id'])) {
        $channels[] = 'private-service.' . $payload['public_id'];
    }
    if ($event['aggregate_type'] === 'service' && !empty($payload['driver_id']) && empty($payload['driver_user_id'])) {
        $driver = $db->prepare('SELECT user_id FROM drivers WHERE id = :id');
        $driver->execute(['id' => $payload['driver_id']]);
        $driverUserId = $driver->fetchColumn();
        if ($driverUserId) {
            $payload['driver_user_id'] = (int) $driverUserId;
            $channels[] = 'private-driver.' . (int) $driverUserId;
        }
    }
    if ($event['aggregate_type'] === 'driver') {
        $driver = $db->prepare('SELECT user_id FROM drivers WHERE id = :id');
        $driver->execute(['id' => $event['aggregate_id']]);
        $driverUserId = $driver->fetchColumn();
        if ($driverUserId) {
            $payload['driver_user_id'] = (int) $driverUserId;
            $channels[] = 'private-driver.' . (int) $driverUserId;
        }
    }

    try {
        $pusher->trigger(array_values(array_unique($channels)), $event['event_name'], $payload);
        $done = $db->prepare('UPDATE outbox_events SET published_at = UTC_TIMESTAMP(3), attempts = attempts + 1 WHERE id = :id');
        $done->execute(['id' => $event['id']]);
        $processed++;
    } catch (Throwable $error) {
        $attempts = min(8, (int) $event['attempts'] + 1);
        $delay = min(300, 2 ** $attempts);
        $retry = $db->prepare('UPDATE outbox_events SET attempts = attempts + 1,
            available_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL :delay SECOND) WHERE id = :id');
        $retry->execute(['delay' => $delay, 'id' => $event['id']]);
        error_log('Outbox publish failed for ' . $event['id'] . ': ' . $error->getMessage());
    }
}

echo 'Published ' . $processed . ' outbox event(s).' . PHP_EOL;
    if (!$watch) {
        break;
    }
    if ($processed === 0) {
        usleep(500000);
    }
} while (true);
