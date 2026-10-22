<?php

declare(strict_types=1);

namespace Halcones\Modules\Services;

use Halcones\Http\ApiException;
use Halcones\Infrastructure\Database;
use Halcones\Infrastructure\OutboxService;
use PDO;
use Throwable;

final class RideService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function create(array $data, ?int $customerId, ?int $actorId, string $channel, ?string $externalId = null): array
    {
        $origin = trim((string) ($data['origin'] ?? ''));
        $destination = trim((string) ($data['destination'] ?? ''));
        if ($origin === '' || $destination === '' || mb_strlen($origin) > 500 || mb_strlen($destination) > 500) {
            throw new ApiException('Origen y destino son obligatorios.', 422, 'VALIDATION_ERROR');
        }

        if ($externalId !== null) {
            $existing = $this->db->prepare('SELECT id, public_id, status FROM services WHERE channel = :channel AND external_request_id = :external_id');
            $existing->execute(['channel' => $channel, 'external_id' => $externalId]);
            $row = $existing->fetch();
            if ($row) {
                return $row;
            }
        }

        $publicId = OutboxService::uuid();
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO services (public_id, customer_id, created_by_user_id, channel, external_request_id,
                    origin_address, origin_latitude, origin_longitude, destination_address,
                    destination_latitude, destination_longitude, estimated_fare, status)
                 VALUES (:public_id, :customer_id, :actor_id, :channel, :external_id, :origin, :origin_lat,
                    :origin_lng, :destination, :destination_lat, :destination_lng, :fare, \'REQUESTED\')'
            );
            $insert->execute([
                'public_id' => $publicId,
                'customer_id' => $customerId,
                'actor_id' => $actorId,
                'channel' => $channel,
                'external_id' => $externalId,
                'origin' => $origin,
                'origin_lat' => $this->coordinate($data['origin_latitude'] ?? null, -90, 90),
                'origin_lng' => $this->coordinate($data['origin_longitude'] ?? null, -180, 180),
                'destination' => $destination,
                'destination_lat' => $this->coordinate($data['destination_latitude'] ?? null, -90, 90),
                'destination_lng' => $this->coordinate($data['destination_longitude'] ?? null, -180, 180),
                'fare' => $this->money($data['estimated_fare'] ?? null),
            ]);
            $serviceId = (int) $this->db->lastInsertId();
            $this->statusHistory($serviceId, null, 'REQUESTED', $actorId, null);
            OutboxService::append($this->db, 'service.requested', 'service', $serviceId, [
                'service_id' => $serviceId,
                'public_id' => $publicId,
                'status' => 'REQUESTED',
                'origin' => $origin,
                'destination' => $destination,
            ]);
            $this->db->commit();

            return $this->find($serviceId);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 's.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['driver_id'])) {
            $where[] = 's.assigned_driver_id = :driver_id';
            $params['driver_id'] = (int) $filters['driver_id'];
        }
        if (!empty($filters['customer_id'])) {
            $where[] = 's.customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        $sql = 'SELECT s.id, s.public_id, s.channel, s.origin_address, s.origin_latitude, s.origin_longitude,
                   s.destination_address, s.destination_latitude, s.destination_longitude, s.estimated_fare,
                   s.final_fare, s.status, s.requested_at, s.completed_at, s.assigned_driver_id,
                       u.name AS customer_name, u.phone AS customer_phone, du.name AS driver_name, p.method AS payment_method
                FROM services s
                LEFT JOIN users u ON u.id = s.customer_id
                LEFT JOIN drivers d ON d.id = s.assigned_driver_id
            LEFT JOIN users du ON du.id = d.user_id
            LEFT JOIN payments p ON p.service_id = s.id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.requested_at DESC LIMIT 100';
        $query = $this->db->prepare($sql);
        $query->execute($params);

        return $query->fetchAll();
    }

    public function find(int $serviceId): array
    {
        $query = $this->db->prepare(
                'SELECT s.*, u.name AS customer_name, u.phone AS customer_phone,
                    d.id AS driver_id, du.name AS driver_name, d.plate AS driver_plate,
                    p.method AS payment_method, p.amount AS payment_amount
             FROM services s
             LEFT JOIN users u ON u.id = s.customer_id
             LEFT JOIN drivers d ON d.id = s.assigned_driver_id
             LEFT JOIN users du ON du.id = d.user_id
                 LEFT JOIN payments p ON p.service_id = s.id
             WHERE s.id = :id LIMIT 1'
        );
        $query->execute(['id' => $serviceId]);
        $service = $query->fetch();
        if (!$service) {
            throw new ApiException('Servicio no encontrado.', 404, 'NOT_FOUND');
        }

        return $service;
    }

    public function assign(int $serviceId, int $operatorId, ?int $driverId = null): array
    {
        $this->db->beginTransaction();
        try {
            $serviceQuery = $this->db->prepare('SELECT * FROM services WHERE id = :id FOR UPDATE');
            $serviceQuery->execute(['id' => $serviceId]);
            $service = $serviceQuery->fetch();
            if (!$service) {
                throw new ApiException('Servicio no encontrado.', 404, 'NOT_FOUND');
            }
            if (!in_array($service['status'], ['REQUESTED', 'PENDING_ASSIGNMENT'], true)) {
                throw new ApiException('El servicio ya no está disponible para asignación.', 409, 'INVALID_SERVICE_STATE');
            }
            if ($service['status'] === 'REQUESTED') {
                $this->db->prepare('UPDATE services SET status = \'PENDING_ASSIGNMENT\' WHERE id = :id')
                    ->execute(['id' => $serviceId]);
                $this->statusHistory($serviceId, 'REQUESTED', 'PENDING_ASSIGNMENT', $operatorId, null);
                $service['status'] = 'PENDING_ASSIGNMENT';
            }

            $params = [];
            $driverSql = 'SELECT d.id, d.user_id, d.last_latitude, d.last_longitude
                          FROM drivers d JOIN users u ON u.id = d.user_id
                          WHERE d.status = \'AVAILABLE\' AND u.account_status = \'ACTIVE\'
                            AND d.last_location_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)';
            if ($driverId !== null) {
                $driverSql .= ' AND d.id = :driver_id';
                $params['driver_id'] = $driverId;
            }
            $driverSql .= ' ORDER BY d.last_location_at DESC LIMIT 50 FOR UPDATE';
            $driversQuery = $this->db->prepare($driverSql);
            $driversQuery->execute($params);
            $drivers = $driversQuery->fetchAll();
            if (!$drivers) {
                throw new ApiException('No hay conductores disponibles con GPS reciente.', 409, 'NO_AVAILABLE_DRIVER');
            }

            $driver = $this->nearestDriver($drivers, $service);
            $assignmentMode = $driverId === null ? 'AUTOMATIC' : 'MANUAL';
            $assignmentInsert = $this->db->prepare(
                'INSERT INTO service_assignments (service_id, driver_id, assigned_by_user_id, assignment_mode, status)
                 VALUES (:service_id, :driver_id, :operator_id, :mode, \'OFFERED\')'
            );
            $assignmentInsert->execute([
                'service_id' => $serviceId,
                'driver_id' => $driver['id'],
                'operator_id' => $operatorId,
                'mode' => $assignmentMode,
            ]);
            $assignmentId = (int) $this->db->lastInsertId();

            $this->db->prepare('INSERT INTO current_driver_assignments (driver_id, service_id, assignment_id) VALUES (:driver_id, :service_id, :assignment_id)')
                ->execute(['driver_id' => $driver['id'], 'service_id' => $serviceId, 'assignment_id' => $assignmentId]);
            $this->db->prepare('UPDATE drivers SET status = \'BUSY\' WHERE id = :id AND status = \'AVAILABLE\'')
                ->execute(['id' => $driver['id']]);
            $this->driverHistory((int) $driver['id'], 'AVAILABLE', 'BUSY', $operatorId, null);
            $this->db->prepare('UPDATE services SET status = \'OFFERED\', assigned_driver_id = :driver_id WHERE id = :id')
                ->execute(['driver_id' => $driver['id'], 'id' => $serviceId]);
            $this->statusHistory($serviceId, $service['status'], 'OFFERED', $operatorId, null);

            $publicService = $this->find($serviceId);
            OutboxService::append($this->db, 'service.offered', 'service', $serviceId, [
                'service_id' => $serviceId,
                'public_id' => $service['public_id'],
                'driver_id' => (int) $driver['id'],
                'driver_user_id' => (int) $driver['user_id'],
                'status' => 'OFFERED',
                'origin' => $service['origin_address'],
                'destination' => $service['destination_address'],
            ]);
            $this->db->commit();

            return $publicService;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function action(int $serviceId, int $userId, string $role, string $action, array $data = []): array
    {
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare(
                'SELECT s.*, d.id AS driver_id, d.user_id AS driver_user_id
                 FROM services s LEFT JOIN drivers d ON d.id = s.assigned_driver_id
                 WHERE s.id = :id FOR UPDATE'
            );
            $query->execute(['id' => $serviceId]);
            $service = $query->fetch();
            if (!$service) {
                throw new ApiException('Servicio no encontrado.', 404, 'NOT_FOUND');
            }
            $isAssignedDriver = (int) ($service['driver_user_id'] ?? 0) === $userId;
            if (in_array($action, ['accept', 'reject', 'en-route', 'arrive', 'start', 'complete'], true) && !$isAssignedDriver) {
                throw new ApiException('El servicio no está asignado a este conductor.', 403, 'FORBIDDEN');
            }
            if ($action === 'cancel' && !$isAssignedDriver && !in_array($role, ['OPERATOR', 'ADMIN'], true)) {
                throw new ApiException('No tienes permiso para cancelar este servicio.', 403, 'FORBIDDEN');
            }

            if ($action === 'reject') {
                if ($service['status'] !== 'OFFERED') {
                    throw new ApiException('Solo se puede rechazar una oferta pendiente.', 409, 'INVALID_SERVICE_STATE');
                }
                $this->releaseAssignment($service, $userId, 'REJECTED');
                $target = 'PENDING_ASSIGNMENT';
                $service['assigned_driver_id'] = null;
            } else {
                $targetMap = [
                    'accept' => ['OFFERED', 'ACCEPTED'],
                    'en-route' => ['ACCEPTED', 'EN_ROUTE'],
                    'arrive' => ['EN_ROUTE', 'ARRIVED'],
                    'start' => ['ARRIVED', 'IN_PROGRESS'],
                    'complete' => ['IN_PROGRESS', 'COMPLETED'],
                    'cancel' => [null, 'CANCELLED'],
                ];
                if (!isset($targetMap[$action])) {
                    throw new ApiException('Acción no válida.', 422, 'VALIDATION_ERROR');
                }
                [$expected, $target] = $targetMap[$action];
                if ($expected !== null && $service['status'] !== $expected) {
                    throw new ApiException('La transición solicitada no está permitida.', 409, 'INVALID_SERVICE_STATE');
                }
                if ($target === 'CANCELLED' && in_array($service['status'], ['COMPLETED', 'CANCELLED', 'EXPIRED', 'REJECTED'], true)) {
                    throw new ApiException('El servicio ya está cerrado.', 409, 'INVALID_SERVICE_STATE');
                }
                if ($action === 'complete') {
                    $fare = $this->money($data['final_fare'] ?? $service['estimated_fare']);
                    if ($fare === null || (float) $fare <= 0) {
                        throw new ApiException('La tarifa final debe ser mayor que cero.', 422, 'VALIDATION_ERROR');
                    }
                    $this->db->prepare('UPDATE services SET final_fare = :fare WHERE id = :id')->execute(['fare' => $fare, 'id' => $serviceId]);
                }
                if (in_array($target, ['COMPLETED', 'CANCELLED'], true)) {
                    $this->releaseAssignment($service, $userId, $target);
                }
            }

            $transition = $this->db->prepare('SELECT 1 FROM allowed_status_transitions
                WHERE entity_type = \'SERVICE\' AND from_status = :from_status AND to_status = :to_status LIMIT 1');
            $transition->execute(['from_status' => $service['status'], 'to_status' => $target]);
            if (!$transition->fetchColumn()) {
                throw new ApiException('La transición solicitada no está permitida.', 409, 'INVALID_SERVICE_STATE');
            }

            if ($action === 'accept') {
                $this->db->prepare('UPDATE service_assignments SET status = \'ACCEPTED\', responded_at = UTC_TIMESTAMP(3)
                    WHERE service_id = :service_id AND driver_id = :driver_id AND status = \'OFFERED\' ORDER BY id DESC LIMIT 1')
                    ->execute(['service_id' => $serviceId, 'driver_id' => $service['assigned_driver_id']]);
            }

            $this->db->prepare('UPDATE services SET status = :status, assigned_driver_id = :driver_id,
                accepted_at = IF(:accepted_status = \'ACCEPTED\', UTC_TIMESTAMP(3), accepted_at),
                arrived_at = IF(:arrived_status = \'ARRIVED\', UTC_TIMESTAMP(3), arrived_at),
                started_at = IF(:started_status = \'IN_PROGRESS\', UTC_TIMESTAMP(3), started_at),
                completed_at = IF(:completed_status = \'COMPLETED\', UTC_TIMESTAMP(3), completed_at),
                cancelled_at = IF(:cancelled_status = \'CANCELLED\', UTC_TIMESTAMP(3), cancelled_at)
                WHERE id = :id')->execute([
                    'status' => $target,
                    'driver_id' => $service['assigned_driver_id'],
                    'accepted_status' => $target,
                    'arrived_status' => $target,
                    'started_status' => $target,
                    'completed_status' => $target,
                    'cancelled_status' => $target,
                    'id' => $serviceId,
                ]);
            $this->statusHistory($serviceId, $service['status'], $target, $userId, $data['reason'] ?? null);
            OutboxService::append($this->db, 'service.updated', 'service', $serviceId, [
                'service_id' => $serviceId,
                'public_id' => $service['public_id'],
                'status' => $target,
                'driver_id' => $service['assigned_driver_id'] === null ? null : (int) $service['assigned_driver_id'],
            ]);
            $this->db->commit();

            return $this->find($serviceId);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function recordPayment(int $serviceId, int $userId, array $data, string $idempotencyKey): array
    {
        $method = strtoupper(trim((string) ($data['method'] ?? '')));
        if (!in_array($method, ['CASH', 'TRANSFER'], true) || $idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new ApiException('Método de pago o clave de idempotencia inválidos.', 422, 'VALIDATION_ERROR');
        }

        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT status, final_fare FROM services WHERE id = :id FOR UPDATE');
            $query->execute(['id' => $serviceId]);
            $service = $query->fetch();
            if (!$service || $service['status'] !== 'COMPLETED' || (float) $service['final_fare'] <= 0) {
                throw new ApiException('Solo se puede cobrar un servicio completado con tarifa final.', 409, 'INVALID_PAYMENT_STATE');
            }

            $existing = $this->db->prepare('SELECT * FROM payments WHERE service_id = :service_id OR idempotency_key = :idempotency_key LIMIT 1 FOR UPDATE');
            $existing->execute(['service_id' => $serviceId, 'idempotency_key' => $idempotencyKey]);
            $payment = $existing->fetch();
            if ($payment) {
                if ($payment['idempotency_key'] !== $idempotencyKey || (int) $payment['service_id'] !== $serviceId) {
                    throw new ApiException('Este servicio ya tiene un pago registrado.', 409, 'PAYMENT_ALREADY_RECORDED');
                }
                $this->db->commit();
                return $payment;
            }

            $insert = $this->db->prepare('INSERT INTO payments (service_id, recorded_by_user_id, method, amount, reference, idempotency_key)
                VALUES (:service_id, :user_id, :method, :amount, :reference, :idempotency_key)');
            $insert->execute([
                'service_id' => $serviceId,
                'user_id' => $userId,
                'method' => $method,
                'amount' => $service['final_fare'],
                'reference' => isset($data['reference']) ? trim((string) $data['reference']) : null,
                'idempotency_key' => $idempotencyKey,
            ]);
            $paymentId = (int) $this->db->lastInsertId();
            OutboxService::append($this->db, 'payment.recorded', 'payment', $paymentId, [
                'payment_id' => $paymentId,
                'service_id' => $serviceId,
                'method' => $method,
                'amount' => $service['final_fare'],
            ]);
            $this->db->commit();

            $result = $this->db->prepare('SELECT * FROM payments WHERE id = :id');
            $result->execute(['id' => $paymentId]);
            return $result->fetch();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function financialReport(string $from, string $to, ?int $driverId): array
    {
        $params = ['from' => $from, 'to' => $to];
        $driverFilter = '';
        if ($driverId !== null) {
            $driverFilter = ' AND s.assigned_driver_id = :driver_id';
            $params['driver_id'] = $driverId;
        }
        $query = $this->db->prepare(
            'SELECT p.method, COUNT(*) AS payment_count, SUM(p.amount) AS total
             FROM payments p JOIN services s ON s.id = p.service_id
             WHERE p.status = \'RECORDED\' AND p.paid_at >= :from AND p.paid_at < :to' . $driverFilter . '
             GROUP BY p.method'
        );
        $query->execute($params);

        return $query->fetchAll();
    }

    private function releaseAssignment(array $service, int $actorId, string $assignmentStatus): void
    {
        if (empty($service['assigned_driver_id'])) {
            return;
        }
        $driverId = (int) $service['assigned_driver_id'];
        $this->db->prepare('UPDATE service_assignments SET status = :status, responded_at = UTC_TIMESTAMP(3)
            WHERE service_id = :service_id AND status IN (\'OFFERED\', \'ACCEPTED\') ORDER BY id DESC LIMIT 1')
            ->execute(['status' => $assignmentStatus, 'service_id' => $service['id']]);
        $this->db->prepare('DELETE FROM current_driver_assignments WHERE driver_id = :driver_id AND service_id = :service_id')
            ->execute(['driver_id' => $driverId, 'service_id' => $service['id']]);
        $this->db->prepare('UPDATE drivers SET status = \'AVAILABLE\' WHERE id = :id AND status = \'BUSY\'')
            ->execute(['id' => $driverId]);
        $this->driverHistory($driverId, 'BUSY', 'AVAILABLE', $actorId, null);
    }

    private function nearestDriver(array $drivers, array $service): array
    {
        if ($service['origin_latitude'] === null || $service['origin_longitude'] === null) {
            return $drivers[0];
        }
        usort($drivers, static function (array $a, array $b) use ($service): int {
            $distance = static function (array $driver) use ($service): float {
                if ($driver['last_latitude'] === null || $driver['last_longitude'] === null) {
                    return INF;
                }
                $latDelta = deg2rad((float) $driver['last_latitude'] - (float) $service['origin_latitude']);
                $lngDelta = deg2rad((float) $driver['last_longitude'] - (float) $service['origin_longitude']);
                $aValue = sin($latDelta / 2) ** 2 + cos(deg2rad((float) $service['origin_latitude']))
                    * cos(deg2rad((float) $driver['last_latitude'])) * sin($lngDelta / 2) ** 2;
                return 6371000 * 2 * atan2(sqrt($aValue), sqrt(1 - $aValue));
            };
            return $distance($a) <=> $distance($b);
        });

        return $drivers[0];
    }

    private function statusHistory(int $serviceId, ?string $from, string $to, ?int $actorId, ?string $reason): void
    {
        $this->db->prepare('INSERT INTO service_status_history (service_id, from_status, to_status, changed_by_user_id, reason)
            VALUES (:service_id, :from_status, :to_status, :actor_id, :reason)')
            ->execute(['service_id' => $serviceId, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actorId, 'reason' => $reason]);
    }

    private function driverHistory(int $driverId, string $from, string $to, ?int $actorId, ?string $reason): void
    {
        $this->db->prepare('INSERT INTO driver_status_history (driver_id, from_status, to_status, changed_by_user_id, reason)
            VALUES (:driver_id, :from_status, :to_status, :actor_id, :reason)')
            ->execute(['driver_id' => $driverId, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actorId, 'reason' => $reason]);
    }

    private function coordinate($value, float $min, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new ApiException('Coordenada fuera de rango.', 422, 'VALIDATION_ERROR');
        }
        return (float) $value;
    }

    private function money($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 9999999999) {
            throw new ApiException('Valor monetario inválido.', 422, 'VALIDATION_ERROR');
        }
        return number_format((float) $value, 2, '.', '');
    }
}
