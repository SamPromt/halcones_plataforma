<?php

declare(strict_types=1);

namespace Halcones\Security;

use Firebase\JWT\Key;
use Firebase\JWT\JWT;
use Halcones\Http\ApiException;
use Throwable;

final class JwtService
{
    private string $secret;
    private string $issuer;
    private int $accessTtl;

    public function __construct()
    {
        $this->secret = $_ENV['JWT_SECRET'] ?? '';
        $this->issuer = $_ENV['JWT_ISSUER'] ?? 'halcones-api';
        $this->accessTtl = max(60, (int) ($_ENV['JWT_ACCESS_TTL'] ?? 900));

        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must contain at least 32 characters.');
        }
    }

    public function issue(int $userId, string $role, string $sessionId): array
    {
        $now = time();
        $expiresAt = $now + $this->accessTtl;
        $token = JWT::encode([
            'iss' => $this->issuer,
            'sub' => (string) $userId,
            'role' => $role,
            'sid' => $sessionId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
        ], $this->secret, 'HS256');

        return ['token' => $token, 'expires_at' => gmdate(DATE_ATOM, $expiresAt)];
    }

    public function decode(string $token): array
    {
        try {
            $claims = (array) JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable) {
            throw new ApiException('Token inválido o vencido.', 401, 'UNAUTHENTICATED');
        }

        if (($claims['iss'] ?? null) !== $this->issuer || empty($claims['sub']) || empty($claims['sid'])) {
            throw new ApiException('Token inválido.', 401, 'UNAUTHENTICATED');
        }

        return $claims;
    }
}
