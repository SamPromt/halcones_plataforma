<?php

declare(strict_types=1);

namespace Halcones\Infrastructure;

use Pusher\Pusher;
use RuntimeException;

final class PusherFactory
{
    public static function create(): Pusher
    {
        $appId = $_ENV['PUSHER_APP_ID'] ?? '';
        $key = $_ENV['PUSHER_APP_KEY'] ?? '';
        $secret = $_ENV['PUSHER_APP_SECRET'] ?? '';
        if ($appId === '' || $key === '' || $secret === '') {
            throw new RuntimeException('Pusher credentials are not configured.');
        }

        return new Pusher($key, $secret, $appId, [
            'host' => $_ENV['PUSHER_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['PUSHER_PORT'] ?? 6001),
            'scheme' => $_ENV['PUSHER_SCHEME'] ?? 'http',
            'useTLS' => ($_ENV['PUSHER_SCHEME'] ?? 'http') === 'https',
            'encrypted' => ($_ENV['PUSHER_SCHEME'] ?? 'http') === 'https',
        ]);
    }
}
