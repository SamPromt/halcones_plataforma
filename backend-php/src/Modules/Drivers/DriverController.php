<?php

declare(strict_types=1);

namespace Halcones\Modules\Drivers;

use Halcones\Http\ApiException;
use Halcones\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DriverController
{
    public function __construct(private DriverService $drivers)
    {
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        return ApiResponse::json($response, ['data' => $this->drivers->profile((int) $auth['sub'])]);
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        $body = (array) $request->getParsedBody();
        $status = strtoupper(trim((string) ($body['status'] ?? '')));
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
        return ApiResponse::json($response, ['data' => $this->drivers->updateStatus((int) $auth['sub'], $status, $reason)]);
    }

    public function location(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        return ApiResponse::json($response, ['data' => $this->drivers->updateLocation((int) $auth['sub'], (array) $request->getParsedBody())]);
    }

    public function available(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        if (!in_array($auth['role'] ?? '', ['OPERATOR', 'ADMIN'], true)) {
            throw new ApiException('No tienes permiso para consultar la flota.', 403, 'FORBIDDEN');
        }
        return ApiResponse::json($response, ['data' => $this->drivers->available()]);
    }
}
