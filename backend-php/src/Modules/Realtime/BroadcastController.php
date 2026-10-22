<?php

declare(strict_types=1);

namespace Halcones\Modules\Realtime;

use Halcones\Http\ApiException;
use Halcones\Http\ApiResponse;
use Halcones\Infrastructure\Database;
use Halcones\Infrastructure\PusherFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BroadcastController
{
    public function authorize(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        $body = (array) $request->getParsedBody();
        $channel = trim((string) ($body['channel_name'] ?? ''));
        $socketId = trim((string) ($body['socket_id'] ?? ''));
        if (!preg_match('/^private-(operators|driver\.\d+|service\.[0-9a-f-]{36})$/i', $channel)
            || !preg_match('/^\d+\.\d+$/', $socketId)) {
            throw new ApiException('Canal o socket inválido.', 422, 'VALIDATION_ERROR');
        }

        $userId = (int) $auth['sub'];
        $role = $auth['role'] ?? '';
        if ($channel === 'private-operators' && !in_array($role, ['OPERATOR', 'ADMIN'], true)) {
            throw new ApiException('Canal no autorizado.', 403, 'FORBIDDEN');
        }
        if (preg_match('/^private-driver\.(\d+)$/', $channel, $match) && (int) $match[1] !== $userId) {
            throw new ApiException('Canal no autorizado.', 403, 'FORBIDDEN');
        }
        if (preg_match('/^private-service\.([0-9a-f-]{36})$/i', $channel, $match)) {
            if (!in_array($role, ['OPERATOR', 'ADMIN'], true)) {
                $query = Database::connection()->prepare(
                    'SELECT 1 FROM services s LEFT JOIN drivers d ON d.id = s.assigned_driver_id
                     WHERE s.public_id = :public_id AND (s.customer_id = :customer_id OR d.user_id = :driver_user_id) LIMIT 1'
                );
                $query->execute(['public_id' => $match[1], 'customer_id' => $userId, 'driver_user_id' => $userId]);
                if (!$query->fetchColumn()) {
                    throw new ApiException('Canal no autorizado.', 403, 'FORBIDDEN');
                }
            }
        }

        $pusher = PusherFactory::create();
        $authorization = $pusher->authorizeChannel($channel, $socketId);
        return ApiResponse::json($response, json_decode($authorization, true, 512, JSON_THROW_ON_ERROR));
    }
}
