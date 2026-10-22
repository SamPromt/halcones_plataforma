<?php

declare(strict_types=1);

namespace Halcones\Modules\Services;

use Halcones\Http\ApiException;
use Halcones\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ServiceController
{
    public function __construct(private RideService $rides)
    {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        $role = $auth['role'] ?? '';
        if (!in_array($role, ['CUSTOMER', 'OPERATOR', 'ADMIN'], true)) {
            throw new ApiException('No tienes permiso para crear solicitudes.', 403, 'FORBIDDEN');
        }
        $body = (array) $request->getParsedBody();
        $customerId = $role === 'CUSTOMER' ? (int) $auth['sub'] : (isset($body['customer_id']) ? (int) $body['customer_id'] : null);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        $service = $this->rides->create($body, $customerId, (int) $auth['sub'], 'WEB', $idempotencyKey !== '' ? $idempotencyKey : null);
        return ApiResponse::json($response, ['data' => $service], 201);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        $params = $request->getQueryParams();
        $driverId = null;
        $customerId = null;
        if (($auth['role'] ?? '') === 'DRIVER') {
            $profile = (new \Halcones\Modules\Drivers\DriverService())->profile((int) $auth['sub']);
            $driverId = (int) $profile['id'];
        } elseif (($auth['role'] ?? '') === 'CUSTOMER') {
            $customerId = (int) $auth['sub'];
        } elseif (!in_array($auth['role'] ?? '', ['OPERATOR', 'ADMIN'], true)) {
            throw new ApiException('No tienes permiso para consultar servicios.', 403, 'FORBIDDEN');
        }
        return ApiResponse::json($response, ['data' => $this->rides->list([
            'status' => isset($params['status']) ? strtoupper((string) $params['status']) : null,
            'driver_id' => $driverId,
            'customer_id' => $customerId,
        ])]);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $service = $this->rides->find((int) $args['id']);
        $auth = $request->getAttribute('auth');
        if (($auth['role'] ?? '') === 'DRIVER' && (int) ($service['driver_user_id'] ?? 0) !== (int) $auth['sub']) {
            throw new ApiException('No tienes permiso para consultar este servicio.', 403, 'FORBIDDEN');
        }
        if (($auth['role'] ?? '') === 'CUSTOMER' && (int) ($service['customer_id'] ?? 0) !== (int) $auth['sub']) {
            throw new ApiException('No tienes permiso para consultar este servicio.', 403, 'FORBIDDEN');
        }
        return ApiResponse::json($response, ['data' => $service]);
    }

    public function assign(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        $body = (array) $request->getParsedBody();
        if (!isset($body['driver_id']) || (int) $body['driver_id'] < 1) {
            throw new ApiException('driver_id es obligatorio para la asignación manual.', 422, 'VALIDATION_ERROR');
        }
        $driverId = (int) $body['driver_id'];
        return ApiResponse::json($response, ['data' => $this->rides->assign((int) $args['id'], (int) $auth['sub'], $driverId)]);
    }

    public function assignAuto(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        return ApiResponse::json($response, ['data' => $this->rides->assign((int) $args['id'], (int) $auth['sub'])]);
    }

    public function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        return ApiResponse::json($response, ['data' => $this->rides->action(
            (int) $args['id'],
            (int) $auth['sub'],
            (string) ($auth['role'] ?? ''),
            (string) $args['action'],
            (array) $request->getParsedBody()
        )]);
    }

    public function payment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        if (!in_array($auth['role'] ?? '', ['DRIVER', 'OPERATOR', 'ADMIN'], true)) {
            throw new ApiException('No tienes permiso para registrar pagos.', 403, 'FORBIDDEN');
        }
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        return ApiResponse::json($response, ['data' => $this->rides->recordPayment(
            (int) $args['id'], (int) $auth['sub'], (array) $request->getParsedBody(), $idempotencyKey
        )], 201);
    }

    public function financialReport(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth');
        if (!in_array($auth['role'] ?? '', ['OPERATOR', 'ADMIN', 'DRIVER'], true)) {
            throw new ApiException('No tienes permiso para consultar reportes.', 403, 'FORBIDDEN');
        }
        $params = $request->getQueryParams();
        $from = (string) ($params['from'] ?? '');
        $to = (string) ($params['to'] ?? '');
        if (!$this->validDate($from) || !$this->validDate($to) || $from >= $to) {
            throw new ApiException('El rango de fechas no es válido.', 422, 'VALIDATION_ERROR');
        }
        if (($auth['role'] ?? '') === 'DRIVER') {
            $profile = (new \Halcones\Modules\Drivers\DriverService())->profile((int) $auth['sub']);
            $driverId = (int) $profile['id'];
        } else {
            $driverId = isset($params['driver_id']) ? (int) $params['driver_id'] : null;
        }
        $bogota = new \DateTimeZone('America/Bogota');
        $utc = new \DateTimeZone('UTC');
        $fromUtc = (new \DateTimeImmutable($from . ' 00:00:00', $bogota))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc = (new \DateTimeImmutable($to . ' 00:00:00', $bogota))->setTimezone($utc)->format('Y-m-d H:i:s');
        return ApiResponse::json($response, ['data' => $this->rides->financialReport($fromUtc, $toUtc, $driverId)]);
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
