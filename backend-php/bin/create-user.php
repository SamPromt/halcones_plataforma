<?php

declare(strict_types=1);

use Halcones\Infrastructure\Database;

require dirname(__DIR__) . '/config/bootstrap.php';

if ($argc < 4) {
    fwrite(STDERR, "Usage: php bin/create-user.php <name> <phone> <CUSTOMER|DRIVER|OPERATOR|ADMIN> [driver_code vehicle_type vehicle_model plate]\n");
    exit(2);
}

[$script, $name, $phone, $role] = $argv;
$role = strtoupper($role);
$password = getenv('HALCONES_USER_PASSWORD') ?: '';
if (!in_array($role, ['CUSTOMER', 'DRIVER', 'OPERATOR', 'ADMIN'], true)
    || trim($name) === '' || trim($phone) === '' || strlen($password) < 12) {
    fwrite(STDERR, "Invalid identity or role; set HALCONES_USER_PASSWORD to at least 12 characters.\n");
    exit(2);
}
if ($role === 'DRIVER' && $argc < 8) {
    fwrite(STDERR, "DRIVER requires: driver_code vehicle_type vehicle_model plate.\n");
    exit(2);
}

$db = Database::connection();
$db->beginTransaction();
try {
    $user = $db->prepare('INSERT INTO users (name, phone, password_hash, role)
        VALUES (:name, :phone, :password_hash, :role)');
    $user->execute([
        'name' => trim($name),
        'phone' => trim($phone),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);
    $userId = (int) $db->lastInsertId();

    if ($role === 'DRIVER') {
        $vehicleType = strtoupper($argv[5]);
        if (!in_array($vehicleType, ['CAR', 'MOTORCYCLE', 'VAN', 'OTHER'], true)) {
            throw new InvalidArgumentException('Invalid vehicle type.');
        }
        $driver = $db->prepare('INSERT INTO drivers (user_id, driver_code, vehicle_type, vehicle_model, plate)
            VALUES (:user_id, :driver_code, :vehicle_type, :vehicle_model, :plate)');
        $driver->execute([
            'user_id' => $userId,
            'driver_code' => strtoupper($argv[4]),
            'vehicle_type' => $vehicleType,
            'vehicle_model' => trim($argv[6]),
            'plate' => strtoupper(trim($argv[7])),
        ]);
    }

    $db->commit();
    echo "Created {$role} account with user ID {$userId}." . PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Account creation failed: {$error->getMessage()}" . PHP_EOL);
    exit(1);
}
