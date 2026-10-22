<?php

declare(strict_types=1);

namespace Halcones\Modules\Auth;

use Halcones\Http\ApiException;
use Halcones\Infrastructure\Database;
use Halcones\Security\JwtService;
use PDO;

final class AuthService
{
    private PDO $db;
    private int $refreshTtl;

    public function __construct(private JwtService $jwt)
    {
        $this->db = Database::connection();
        $this->refreshTtl = max(3600, (int) ($_ENV['JWT_REFRESH_TTL'] ?? 2592000));
    }

    public function login(string $phone, string $password): array
    {
        $query = $this->db->prepare(
            'SELECT id, name, phone, role, account_status, password_hash FROM users WHERE phone = :phone LIMIT 1'
        );
        $query->execute(['phone' => $phone]);
        $user = $query->fetch();

        if (!$user || $user['account_status'] !== 'ACTIVE' || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            throw new ApiException('Teléfono o contraseña incorrectos.', 401, 'INVALID_CREDENTIALS');
        }

        return $this->createSession($user);
    }

    public function refresh(string $refreshToken): array
    {
        $tokenHash = hash('sha256', $refreshToken);
        $this->db->beginTransaction();

        try {
            $query = $this->db->prepare(
                'SELECT s.id AS session_id, s.user_id, u.name, u.phone, u.role, u.account_status
                 FROM auth_sessions s JOIN users u ON u.id = s.user_id
                 WHERE s.refresh_token_hash = :token_hash AND s.revoked_at IS NULL
                   AND s.refresh_expires_at > UTC_TIMESTAMP() FOR UPDATE'
            );
            $query->execute(['token_hash' => $tokenHash]);
            $user = $query->fetch();

            if (!$user || $user['account_status'] !== 'ACTIVE') {
                throw new ApiException('La sesión de renovación no es válida.', 401, 'INVALID_REFRESH_TOKEN');
            }

            $newRefreshToken = bin2hex(random_bytes(32));
            $refreshExpiresAt = gmdate('Y-m-d H:i:s', time() + $this->refreshTtl);
            $access = $this->jwt->issue((int) $user['user_id'], $user['role'], $user['session_id']);
            $accessExpiresAt = gmdate('Y-m-d H:i:s', strtotime($access['expires_at']));

            $update = $this->db->prepare(
                'UPDATE auth_sessions SET refresh_token_hash = :token_hash,
                 refresh_expires_at = :refresh_expires, access_expires_at = :access_expires
                 WHERE id = :session_id'
            );
            $update->execute([
                'token_hash' => hash('sha256', $newRefreshToken),
                'refresh_expires' => $refreshExpiresAt,
                'access_expires' => $accessExpiresAt,
                'session_id' => $user['session_id'],
            ]);

            $this->db->commit();

            return [
                'access_token' => $access['token'],
                'token_type' => 'Bearer',
                'expires_at' => $access['expires_at'],
                'refresh_token' => $newRefreshToken,
                'user' => $this->publicUser($user),
            ];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function logout(int $userId, string $sessionId): void
    {
        $query = $this->db->prepare(
            'UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL'
        );
        $query->execute(['id' => $sessionId, 'user_id' => $userId]);
    }

    public function currentUser(int $userId): array
    {
        $query = $this->db->prepare(
            'SELECT id, name, phone, email, role, account_status FROM users WHERE id = :id LIMIT 1'
        );
        $query->execute(['id' => $userId]);
        $user = $query->fetch();
        if (!$user || $user['account_status'] !== 'ACTIVE') {
            throw new ApiException('La cuenta no está disponible.', 401, 'UNAUTHENTICATED');
        }

        return $user;
    }

    private function createSession(array $user): array
    {
        $sessionId = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
        $refreshToken = bin2hex(random_bytes(32));
        $refreshExpiresAt = gmdate('Y-m-d H:i:s', time() + $this->refreshTtl);
        $access = $this->jwt->issue((int) $user['id'], $user['role'], $sessionId);
        $accessExpiresAt = gmdate('Y-m-d H:i:s', strtotime($access['expires_at']));

        $insert = $this->db->prepare(
            'INSERT INTO auth_sessions (id, user_id, refresh_token_hash, access_expires_at, refresh_expires_at)
             VALUES (:id, :user_id, :token_hash, :access_expires, :refresh_expires)'
        );
        $insert->execute([
            'id' => $sessionId,
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $refreshToken),
            'access_expires' => $accessExpiresAt,
            'refresh_expires' => $refreshExpiresAt,
        ]);

        return [
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_at' => $access['expires_at'],
            'refresh_token' => $refreshToken,
            'user' => $this->publicUser($user),
        ];
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? $user['user_id']),
            'name' => $user['name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
        ];
    }
}
