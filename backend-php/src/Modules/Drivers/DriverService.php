<?php

declare(strict_types=1);

namespace Halcones\Modules\Drivers;

use Halcones\Http\ApiException;
use Halcones\Infrastructure\Database;
use Halcones\Infrastructure\OutboxService;
use PDO;
use Throwable;

final class DriverService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function profile(int $userId): array
    {
        $query = $this->db->prepare('SELECT id, user_id, driver_code, vehicle_type, vehicle_model, plate, status,
            last_latitude, last_longitude, last_location_accuracy, last_location_at
            FROM drivers WHERE user_id = :user_id LIMIT 1');
        $query->execute(['user_id' => $userId]);
        $driver = $query->fetch();
        if (!$driver) {
            throw new ApiException('Perfil de conductor no encontrado.', 404, 'DRIVER_NOT_FOUND');
        }
        return $driver;
    }

    public function updateStatus(int $userId, string $status, ?string $reason): array
    {
        if (!in_array($status, ['AVAILABLE', 'OUT_OF_SERVICE'], true)) {
            throw new ApiException('El estado solicitado no es válido.', 422, 'VALIDATION_ERROR');
        }
        if ($status === 'OUT_OF_SERVICE' && trim((string) $reason) === '') {
            throw new ApiException('Indica el motivo para salir de servicio.', 422, 'VALIDATION_ERROR');
        }

        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT id, status FROM drivers WHERE user_id = :user_id FOR UPDATE');
            $query->execute(['user_id' => $userId]);
            $driver = $query->fetch();
            if (!$driver) {
                throw new ApiException('Perfil de conductor no encontrado.', 404, 'DRIVER_NOT_FOUND');
            }
            if ($driver['status'] === 'BUSY') {
                throw new ApiException('No puedes cambiar disponibilidad durante un servicio activo.', 409, 'DRIVER_BUSY');
            }

            $newStatus = $status === 'AVAILABLE' ? 'AVAILABLE' : 'OUT_OF_SERVICE';
            if ($driver['status'] !== $newStatus) {
                $this->db->prepare('UPDATE drivers SET status = :status, out_of_service_reason = :reason WHERE id = :id')
                    ->execute(['status' => $newStatus, 'reason' => $newStatus === 'OUT_OF_SERVICE' ? $reason : null, 'id' => $driver['id']]);
                $this->db->prepare('INSERT INTO driver_status_history (driver_id, from_status, to_status, changed_by_user_id, reason)
                    VALUES (:driver_id, :from_status, :to_status, :user_id, :reason)')->execute([
                        'driver_id' => $driver['id'], 'from_status' => $driver['status'], 'to_status' => $newStatus,
                        'user_id' => $userId, 'reason' => $reason,
                    ]);
                OutboxService::append($this->db, 'driver.status.updated', 'driver', (int) $driver['id'], [
                    'driver_id' => (int) $driver['id'], 'status' => $newStatus,
                ]);
            }
            $this->db->commit();
            return $this->profile($userId);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function updateLocation(int $userId, array $data): array
    {
        $latitude = $this->coordinate($data['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($data['longitude'] ?? null, -180, 180);
        $accuracy = isset($data['accuracy']) && is_numeric($data['accuracy']) ? max(0, min(10000, (float) $data['accuracy'])) : null;
        $speed = isset($data['speed']) && is_numeric($data['speed']) ? max(0, min(500, (float) $data['speed'])) : null;
        $heading = isset($data['heading']) && is_numeric($data['heading']) ? fmod(max(0, (float) $data['heading']), 360) : null;

        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT id, status, last_latitude, last_longitude, last_location_at,
                (last_location_at IS NOT NULL AND last_location_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 3 SECOND)) AS rate_limited
                FROM drivers WHERE user_id = :user_id FOR UPDATE');
            $query->execute(['user_id' => $userId]);
            $driver = $query->fetch();
            if (!$driver) {
                throw new ApiException('Perfil de conductor no encontrado.', 404, 'DRIVER_NOT_FOUND');
            }
            if (!empty($driver['rate_limited'])) {
                $this->db->commit();
                return ['accepted' => false, 'reason' => 'rate_limited'];
            }

            $recordedAt = gmdate('Y-m-d H:i:s');
            $this->db->prepare('UPDATE drivers SET last_latitude = :latitude, last_longitude = :longitude,
                last_location_accuracy = :accuracy, last_location_at = UTC_TIMESTAMP(3)
                WHERE id = :id')->execute([
                    'latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => $accuracy, 'id' => $driver['id'],
                ]);
            $this->db->prepare('INSERT INTO driver_locations (driver_id, latitude, longitude, accuracy_meters,
                speed_meters_per_second, heading_degrees, recorded_at)
                VALUES (:driver_id, :latitude, :longitude, :accuracy, :speed, :heading, UTC_TIMESTAMP(3))')->execute([
                    'driver_id' => $driver['id'], 'latitude' => $latitude, 'longitude' => $longitude,
                    'accuracy' => $accuracy, 'speed' => $speed, 'heading' => $heading,
                ]);
            OutboxService::append($this->db, 'driver.location.updated', 'driver', (int) $driver['id'], [
                'driver_id' => (int) $driver['id'], 'latitude' => $latitude, 'longitude' => $longitude,
                'accuracy' => $accuracy, 'recorded_at' => $recordedAt,
            ]);
            $this->db->commit();

            return ['accepted' => true, 'status' => $driver['status'], 'recorded_at' => $recordedAt];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function available(): array
    {
        $query = $this->db->query('SELECT d.id, d.driver_code, d.vehicle_type, d.vehicle_model, d.plate, d.status,
            d.last_latitude, d.last_longitude, d.last_location_accuracy, d.last_location_at, u.name
            FROM drivers d JOIN users u ON u.id = d.user_id
            WHERE d.status = \'AVAILABLE\' AND u.account_status = \'ACTIVE\'
              AND d.last_location_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
            ORDER BY d.last_location_at DESC LIMIT 500');
        return $query->fetchAll();
    }

    private function coordinate($value, float $min, float $max): float
    {
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new ApiException('Coordenada fuera de rango.', 422, 'VALIDATION_ERROR');
        }
        return (float) $value;
    }
}
